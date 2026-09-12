<?php

/**
 * Unit tests for PublicationService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\PublicationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests publish/withdraw/rectify flow + catalog-retraction failure branch.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class PublicationServiceTest extends TestCase {

	/**
	 * In-memory object store keyed by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $store = [];

	/**
	 * Auto-increment counter for generated UUIDs.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Build a fully-wired PublicationService over an in-memory ObjectService.
	 *
	 * @param bool $openCatalogi Whether OpenCatalogi is "installed".
	 * @param OpenCatalogiPublisher|null $catalogOverride Optional publisher double.
	 *
	 * @return PublicationService
	 */
	private function makeService(bool $openCatalogi = false, ?OpenCatalogiPublisher $catalogOverride = null): PublicationService {
		$logger = $this->createMock(LoggerInterface::class);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?object {
				if (isset($this->store[(string)$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($this->store[(string)$id]);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): object {
				if ($uuid === null) {
					$this->seq++;
					$uuid = 'obj-' . $this->seq;
				}

				$row = array_merge(['id' => $uuid], $object);
				$this->store[$uuid] = $row;

				// No ->method('getUuid'): production declares getUuid() only as
				// an `@method` tag served through Entity::__call, so it is not a
				// real method — method_exists() is false for it and PHPUnit
				// refuses to configure it. PublicationRepository::extractObjectId()
				// therefore always takes its jsonSerialize() fallback, which is
				// what $row['id'] exercises here (#399).
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(json_encode(['body-1' => ['catalog' => 'cat-1', 'policy' => [], 'attendance' => 'counts']]));
		$configService = new PublicationConfigService($appConfig);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(
			static function (string $appId) use ($openCatalogi): bool {
				return ($appId === 'opencatalogi') ? $openCatalogi : false;
			}
		);

		$eligibility = new PublicationEligibilityService($logger,
			objectService: $objectService,
		);
		$payload = new PublicationPayloadService($container, $logger, $configService);

		$catalog = $catalogOverride;
		if ($catalog === null) {
			$catalog = $this->createMock(OpenCatalogiPublisher::class);
			$catalog->method('publish')->willReturn('');
			$catalog->method('retract')->willReturn(true);
		}

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => '']);

		return new PublicationService($logger, $appManager, $eligibility, $payload, $configService, $catalog, $audit, $objectService);
	}//end makeService()

	/**
	 * Reset the store before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->seq = 0;

	}//end setUp()

	/**
	 * Publishing an enacted decision creates a record and a payload.
	 *
	 * @return void
	 */
	public function testPublishEnactedDecisionCreatesRecord(): void {
		$this->store['dec-1'] = ['id' => 'dec-1', 'title' => 'Begroting', 'lifecycle' => 'enacted', 'outcome' => 'adopted', 'governanceBody' => 'body-1', 'decisionType' => 'meeting-outcome'];

		$service = $this->makeService(openCatalogi: false);
		$result = $service->publish('decision', 'dec-1', 'j.bakker');

		$this->assertSame('published', $result['record']['status']);
		$this->assertSame('Besluit', $result['record']['oriType']);
		$this->assertSame('j.bakker', $result['record']['publishedBy']);
		// OpenCatalogi absent => warning surfaced, never a silent success.
		$this->assertContains('opencatalogi-absent', $result['warnings']);
		// No legacy magic-mapper warning is emitted anymore — the predicate is a
		// normal field on a register-owned object.
		$this->assertNotContains('predicate-unavailable', $result['warnings']);
		// The decision source was stamped published (flow-owned write).
		$this->assertSame('public', $this->store['dec-1']['isPublished']);

		// RBAC model: the derived payload carries publicationDate <= $now so the
		// public-group authorization.read rule makes it anonymously readable.
		$payloadId = $result['record']['payloadObject'];
		$this->assertArrayHasKey('publicationDate', $this->store[$payloadId]);
		$this->assertTrue($this->isPubliclyReadable($this->store[$payloadId]));
		$this->assertNull($this->store[$payloadId]['depublicationDate']);

	}//end testPublishEnactedDecisionCreatesRecord()

	/**
	 * Anonymous read lifecycle: a published payload is public-group readable once
	 * publicationDate <= now, NOT before, and is gone after withdraw sets
	 * depublicationDate.
	 *
	 * @return void
	 */
	public function testAnonReadablePredicateLifecycle(): void {
		$this->store['dec-2'] = ['id' => 'dec-2', 'title' => 'Verordening', 'lifecycle' => 'decided', 'outcome' => 'adopted', 'governanceBody' => 'body-1', 'decisionType' => 'meeting-outcome'];

		$service = $this->makeService(openCatalogi: false);

		// Before publish there is no payload object and nothing is public.
		$publicBefore = array_filter($this->store, fn (array $o): bool => $this->isPubliclyReadable($o));
		$this->assertCount(0, $publicBefore);

		$result = $service->publish('decision', 'dec-2', 'j.bakker');
		$payloadId = $result['record']['payloadObject'];

		// Once published: payload is public-group readable (publicationDate<=now).
		$this->assertTrue($this->isPubliclyReadable($this->store[$payloadId]));

		// A payload whose publicationDate is in the FUTURE must NOT be public.
		$future = array_merge($this->store[$payloadId], ['publicationDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM)]);
		$this->assertFalse($this->isPubliclyReadable($future));

		// Withdraw sets depublicationDate in the past: no longer public.
		$service->withdraw($result['record']['id'], 'j.bakker', 'Ingetrokken');
		$this->assertArrayHasKey('depublicationDate', $this->store[$payloadId]);
		$this->assertFalse($this->isPubliclyReadable($this->store[$payloadId]));

	}//end testAnonReadablePredicateLifecycle()

	/**
	 * Evaluate the public-group RBAC read rule of PublicationPayload:
	 * readable iff publicationDate is set and <= now AND depublicationDate is
	 * unset or in the future. Mirrors the authorization.read match in
	 * decidesk_register.json.
	 *
	 * @param array<string,mixed> $object The candidate payload object.
	 *
	 * @return bool
	 */
	private function isPubliclyReadable(array $object): bool {
		$pub = ($object['publicationDate'] ?? null);
		if (is_string($pub) === false || $pub === '') {
			return false;
		}

		$now = (new \DateTimeImmutable())->getTimestamp();
		$pubTs = (new \DateTimeImmutable($pub))->getTimestamp();
		if ($pubTs > $now) {
			return false;
		}

		$depub = ($object['depublicationDate'] ?? null);
		if (is_string($depub) === true && $depub !== '') {
			return ((new \DateTimeImmutable($depub))->getTimestamp() > $now);
		}

		return true;
	}//end isPubliclyReadable()

	/**
	 * Withdraw requires a non-empty reason.
	 *
	 * @return void
	 */
	public function testWithdrawRequiresReason(): void {
		$this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'decision', 'sourceObject' => 'dec-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => '', 'status' => 'published'];

		$service = $this->makeService();
		$this->expectException(\InvalidArgumentException::class);
		$service->withdraw('rec-1', 'j.bakker', '  ');

	}//end testWithdrawRequiresReason()

	/**
	 * Withdraw records actor, reason, and timestamp and flips status.
	 *
	 * @return void
	 */
	public function testWithdrawRecordsReasonAndResetsSource(): void {
		$this->store['dec-1'] = ['id' => 'dec-1', 'title' => 'x', 'lifecycle' => 'enacted', 'isPublished' => 'public', 'decisionType' => 'motion'];
		$this->store['pay-1'] = ['id' => 'pay-1', 'oriType' => 'Besluit'];
		$this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'decision', 'sourceObject' => 'dec-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => '', 'status' => 'published'];

		$service = $this->makeService();
		$result = $service->withdraw('rec-1', 'j.bakker', 'Onjuiste tekst');

		$this->assertSame('withdrawn', $result['record']['status']);
		$this->assertSame('Onjuiste tekst', $result['record']['withdrawReason']);
		$this->assertSame('j.bakker', $result['record']['withdrawnBy']);
		$this->assertSame('internal', $this->store['dec-1']['isPublished']);

	}//end testWithdrawRecordsReasonAndResetsSource()

	/**
	 * Catalog-retraction failure marks the record pending and surfaces a warning.
	 *
	 * @return void
	 */
	public function testCatalogRetractionFailureSurfaced(): void {
		$this->store['dec-1'] = ['id' => 'dec-1', 'title' => 'x', 'lifecycle' => 'enacted', 'isPublished' => 'public', 'decisionType' => 'motion'];
		$this->store['pay-1'] = ['id' => 'pay-1', 'oriType' => 'Besluit'];
		$this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'decision', 'sourceObject' => 'dec-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => 'catpub-1', 'targetCatalog' => 'cat-1', 'status' => 'published'];

		$failing = $this->createMock(OpenCatalogiPublisher::class);
		$failing->method('publish')->willReturn('');
		$failing->method('retract')->willReturn(false);

		$service = $this->makeService(openCatalogi: true, catalogOverride: $failing);
		$result = $service->withdraw('rec-1', 'j.bakker', 'fout');

		$this->assertSame('pending', $result['record']['catalogRetractionStatus']);
		$this->assertContains('catalog-retraction-failed', $result['warnings']);

	}//end testCatalogRetractionFailureSurfaced()

	/**
	 * Rectify publishes a new version referencing the old one and withdraws it.
	 *
	 * @return void
	 */
	public function testRectifyVersioning(): void {
		$this->store['min-1'] = ['id' => 'min-1', 'title' => 'Notulen', 'lifecycle' => 'approved', 'content' => 'corrected', 'governanceBody' => 'body-1'];
		$this->store['pay-1'] = ['id' => 'pay-1', 'oriType' => 'Verslag'];
		$this->store['rec-1'] = ['id' => 'rec-1', 'sourceType' => 'minutes', 'sourceObject' => 'min-1', 'payloadObject' => 'pay-1', 'payloadVersion' => 1, 'catalogPublication' => '', 'targetCatalog' => 'cat-1', 'status' => 'published'];

		$service = $this->makeService(openCatalogi: false);
		$result = $service->rectify('rec-1', 'j.bakker', 'Correctie');

		$this->assertSame(2, $result['record']['payloadVersion']);
		$this->assertSame(1, $result['record']['rectifiesVersion']);
		// The prior record was withdrawn in the same operation.
		$this->assertSame('withdrawn', $result['previous']['status']);

	}//end testRectifyVersioning()
}//end class
