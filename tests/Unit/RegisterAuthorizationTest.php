<?php

/**
 * Contract tests for decidiq's OpenRegister `authorization` baseline.
 *
 * Every decidiq object is reachable at
 * `/apps/openregister/api/objects/decidiq/<schema>` — the register slug is
 * FROZEN on the old value — the API the frontend uses
 * directly under ADR-022 — and NO decidiq controller guard sits in front of it.
 * What decides who may write there is the `authorization` block on the schema, or
 * failing that on the REGISTER row.
 *
 * This tree had neither. OpenRegister's
 * `Service/Object/PermissionHandler::hasGroupPermission()` tests
 * `empty($authorization)`, and PHP's `empty()` is true for `null` and `[]` alike,
 * so an ABSENT block takes the same default-OPEN branch as an empty one:
 *
 *     if (empty($authorization) === true || $publicOptIn === true) {
 *         if ($this->isDefaultClosedEnforced() === true
 *             && in_array($action, self::DEFAULT_CLOSED_WRITE_ACTIONS, true)
 *             && $publicOptIn === false) { return false; }
 *         return true;                       // <- create / update / delete, granted
 *     }
 *
 * `enforce_default_closed` reads `IAppConfig` with `default: false`, so on a stock
 * instance that guard never fires. Measured before the fix: 93 schemas, 24 with a
 * block (all `read`-only), and this register row with none — 69 schemas, Decision
 * / VotingRound / Vote / Participant / EngagementRecord among them, granting
 * create, update AND delete to any logged-in account.
 *
 * These tests do not re-implement OpenRegister's evaluator — an instrument built
 * from the same source as the bug reports zero. They pin the DECLARATION the
 * evaluator reads, which is the part this repository owns, and they pin the two
 * version fields without which the declaration never reaches an instance.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The register-level authorization baseline is declared, complete, and deployable.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
 */
class RegisterAuthorizationTest extends TestCase {

	/**
	 * Actions OpenRegister treats as writes on an object.
	 *
	 * `PermissionHandler::DEFAULT_CLOSED_WRITE_ACTIONS` and
	 * `ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS` are both exactly this set.
	 *
	 * @var array<int,string>
	 */
	private const WRITE_ACTIONS = ['create', 'update', 'delete'];

	/**
	 * Every canonical action, from `PermissionHandler::CANONICAL_ACTIONS`.
	 *
	 * @var array<int,string>
	 */
	private const CANONICAL_ACTIONS = ['read', 'create', 'update', 'delete', 'list'];

	/**
	 * Schema blocks that read-narrow a schema the SPA writes through the object API.
	 *
	 * OpenRegister uses a schema's block INSTEAD of the register's, whole, and
	 * denies any action a non-empty block omits. So each of these blocks, added
	 * to narrow who may READ, also closed create, update and delete to everyone
	 * but the object owner and a Nextcloud superuser (decidiq#1269). None of them
	 * has a decidiq controller or service that writes it: the SPA's generic
	 * index and detail pages write straight to
	 * `/apps/openregister/api/objects/decidiq/<schema>`. The block must
	 * therefore restate the register's write grants itself.
	 *
	 * @var array<int,string>
	 */
	private const RESTATES_THE_BASELINE_WRITES = [
		'Decision',
		'ParticipatoryBudget',
		'PublicConsultation',
		'BoardEvaluation',
		'GoverningDocument',
		'AncillaryPosition',
		'DeclaredGift',
		'ConfidentialityRestriction',
		'ConfidentialityGround',
		'Commitment',
		'PlannedAgendaItem',
		'AuthorityDelegation',
		'MemberOnboarding',
		'MemberOffboarding',
	];

	/**
	 * Schemas whose writes are owned by a decidiq service, which keeps the object API closed ON PURPOSE.
	 *
	 * Each is written only through a decidiq service that runs its own
	 * per-object guard and then saves with `_rbac: false` (or runs only for a
	 * superuser). Opening a write verb here would let any member go around that
	 * guard through OpenRegister's object API:
	 *
	 *  - ProxyAuthorization: ProxyVoteService (REQ-BPV-001/002). An open create
	 *    would let a member register a proxy in someone else's name.
	 *  - ConflictOfInterest: ConflictOfInterestService (declarant or
	 *    chair/secretary only). Both service docblocks say they rely on this.
	 *  - ConsultationReaction: ReactionIntakeService decides pending versus
	 *    approved. An open create would let a member self-approve a reaction
	 *    into its publicly readable state.
	 *  - PublicationPayload: the public payload the superuser-only publish flow
	 *    writes. An open write would publish content outside that flow.
	 *
	 * @var array<string,string>
	 */
	private const SERVICE_OWNED_WRITES_STAY_CLOSED = [
		'ProxyAuthorization'   => 'ProxyVoteService',
		'ConflictOfInterest'   => 'ConflictOfInterestService',
		'ConsultationReaction' => 'ReactionIntakeService',
		'PublicationPayload'   => 'the publish flow',
	];

	/**
	 * Retired schemas. A later fragment deactivates each one, and nothing may write a new row into them.
	 *
	 * @var array<int,string>
	 */
	private const RETIRED_READ_ONLY = [
		'Toezegging',
		'TermijnagendaItem',
		'Raadsinformatiebrief',
		'TechnischeVraag',
		'Regeling',
		'RegelingVersie',
		'Bevoegdheidstoedeling',
		'WooCategorieMapping',
		'WooBestuursorgaan',
		'Adviesaanvraag',
		'Advies',
		'RoosterVanAftreden',
		'RoosterRegel',
		'Nevenfunctie',
		'Geschenk',
		'GeheimhoudingGrond',
		'Geheimhouding',
	];

	/**
	 * The decoded main register file.
	 *
	 * @var array<string,mixed>
	 */
	private array $register;

	/**
	 * Decode the shipped register JSON.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$path = __DIR__ . '/../../lib/Settings/decidesk_register.json';
		$this->assertFileExists($path);

		$raw = file_get_contents($path);
		$this->assertIsString($raw);

		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded, 'decidesk_register.json must be valid JSON.');

		$this->register = $decoded;
	}//end setUp()

	/**
	 * The `decidesk` register row itself.
	 *
	 * @return array<string,mixed> The register row.
	 */
	private function registerRow(): array {
		$row = $this->register['components']['registers']['decidiq'] ?? null;
		$this->assertIsArray($row, 'The decidesk register row must exist.');

		return $row;
	}//end registerRow()

	/**
	 * The register row declares an authorization block naming EVERY canonical action.
	 *
	 * Completeness is the load-bearing half. Once a block is non-empty,
	 * `hasGroupPermission()` reaches `if (empty($authorization[$action])) return false;`
	 * — so an action this block forgets to name is DENIED to everyone but the
	 * object owner and admin. A block that omitted `create` or `list` would not
	 * secure the app, it would break it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testTheRegisterDeclaresACompleteAuthorizationBlock(): void {
		$authorization = $this->registerRow()['authorization'] ?? null;

		$this->assertIsArray(
			$authorization,
			'The decidesk register row must declare an `authorization` block. Without one, '
				. 'PermissionHandler::hasGroupPermission() takes its default-OPEN branch for every '
				. 'schema that declares no block of its own, granting create/update/delete on '
				. 'Decision, Vote, VotingRound, Participant and EngagementRecord to every '
				. 'authenticated user through OpenRegister own object API.'
		);
		$this->assertNotEmpty($authorization, 'An EMPTY block is evaluated identically to an absent one.');

		foreach (self::CANONICAL_ACTIONS as $action) {
			$this->assertArrayHasKey(
				$action,
				$authorization,
				sprintf(
					'The block must name `%s`: OpenRegister DENIES any action a non-empty block omits, '
						. 'so an unnamed action breaks the app rather than securing it.',
					$action
				)
			);
			$this->assertNotEmpty(
				$authorization[$action],
				sprintf('`%s` must grant to someone — an empty rule list reads as "grant to nobody".', $action)
			);
		}
	}//end testTheRegisterDeclaresACompleteAuthorizationBlock()

	/**
	 * Reads and creates stay open to any authenticated user.
	 *
	 * This is what makes the change safe to land: no read goes dark and every
	 * member can still raise a decision, cast a vote or file a reaction. If this
	 * test ever goes red, the fix has become an outage.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testReadListAndCreateStayOpenToAuthenticatedUsers(): void {
		$authorization = $this->registerRow()['authorization'];

		foreach (['read', 'list', 'create'] as $action) {
			$this->assertContains(
				'authenticated',
				$authorization[$action],
				sprintf('`%s` must stay granted to `authenticated` — narrowing it is an outage, not a fix.', $action)
			);
		}
	}//end testReadListAndCreateStayOpenToAuthenticatedUsers()

	/**
	 * The READ actions still name `public`, so anonymous reads are not collaterally closed.
	 *
	 * This test exists because CI found the omission and nothing local could. Before
	 * any block existed, `hasGroupPermission()` took its default-OPEN branch for EVERY
	 * principal, the anonymous one included — so a block that names only
	 * `authenticated` on `read` does not preserve the status quo, it CLOSES anonymous
	 * reads. Omission is the deny.
	 *
	 * The first version of this block omitted `public`, and all six PHPUnit legs
	 * failed with `NotAuthorizedException: User 'Anonymous' does not have permission
	 * to 'read' objects in schema 'Meeting'` — PHPUnit's CLI has no session, so it
	 * exercises exactly the path a `#[PublicPage]` citizen-participation surface
	 * takes. Closing anonymous reads may well be desirable, but it is a far larger
	 * policy change than the write hole this block fixes and does not belong in it.
	 *
	 * Writes are asserted `public`-free separately, so this cannot drift into a
	 * blanket anonymous grant.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testAnonymousReadsAreNotCollaterallyClosed(): void {
		$authorization = $this->registerRow()['authorization'];

		foreach (['read', 'list'] as $action) {
			$this->assertContains(
				'public',
				$authorization[$action],
				sprintf(
					'`%s` must still name `public`. Before this block existed the default-OPEN branch '
						. 'granted it to the anonymous principal too, so dropping it 403s every '
						. '#[PublicPage] surface — a policy change, not a security fix.',
					$action
				)
			);
		}
	}//end testAnonymousReadsAreNotCollaterallyClosed()

	/**
	 * Update and delete are NOT granted to every authenticated user.
	 *
	 * This is the whole security change, and it is the assertion that would have
	 * failed on `development`. It says nothing about the object OWNER: OpenRegister
	 * bypasses the owner unconditionally, SQL-side, before any rule is consulted, so
	 * an author keeps full control of their own object and only OTHER users lose the
	 * ability to rewrite or destroy it.
	 *
	 * The `public` pseudo-group is asserted absent separately: granting it would
	 * re-open anonymous writes that OpenRegister#1955 closed by default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testUpdateAndDeleteAreNotOpenToEveryAuthenticatedUser(): void {
		$authorization = $this->registerRow()['authorization'];

		foreach (['update', 'delete'] as $action) {
			$this->assertNotContains(
				'authenticated',
				$authorization[$action],
				sprintf(
					'`%s` must NOT be granted to `authenticated`: that is exactly the default-open state '
						. 'this block exists to close, and it is the shape that let a plain user overwrite '
						. "another user's record in docudesk#631.",
					$action
				)
			);
			$this->assertNotContains(
				'public',
				$authorization[$action],
				sprintf('`%s` must not be granted to the `public` pseudo-group.', $action)
			);
		}
	}//end testUpdateAndDeleteAreNotOpenToEveryAuthenticatedUser()

	/**
	 * No write action is granted to the `public` pseudo-group anywhere.
	 *
	 * `publicGroupExplicitlyGranted()` is the one thing that re-opens anonymous
	 * writes past OpenRegister#1955's fail-closed rule, so a stray `public` entry
	 * on a write action would be a wider hole than the one being closed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testNoWriteActionGrantsThePublicPseudoGroup(): void {
		$authorization = $this->registerRow()['authorization'];

		foreach (self::WRITE_ACTIONS as $action) {
			foreach ($authorization[$action] as $entry) {
				$group = is_array($entry) ? ($entry['group'] ?? null) : $entry;
				$this->assertNotSame(
					'public',
					$group,
					sprintf('`%s` must not grant the `public` pseudo-group — that re-opens anonymous writes.', $action)
				);
			}
		}
	}//end testNoWriteActionGrantsThePublicPseudoGroup()

	/**
	 * The register version was bumped, or the block never reaches an instance.
	 *
	 * `ImportHandler`'s register path skips outright when the incoming version is
	 * `<=` the stored one, and — unlike the SCHEMA path, which falls back to a
	 * content comparison — it has NO content-differs escape. A correct block with
	 * an unbumped version is a fix that deploys nowhere and reports success.
	 *
	 * The assertion is against the last version that shipped WITHOUT the block, so
	 * it stays meaningful as the register keeps evolving.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testTheRegisterVersionMovedPastTheUnprotectedRelease(): void {
		$lastUnprotected = '0.7.0';

		$this->assertGreaterThan(
			0,
			version_compare($this->registerRow()['version'] ?? '0.0.0', $lastUnprotected),
			sprintf(
				'The register version must be greater than %s (the last release with no authorization '
					. 'block), or ImportHandler skips the import and the fix never lands.',
				$lastUnprotected
			)
		);
		$this->assertGreaterThan(
			0,
			version_compare($this->register['info']['version'] ?? '0.0.0', $lastUnprotected),
			'The configuration version must move with it.'
		);
	}//end testTheRegisterVersionMovedPastTheUnprotectedRelease()

	/**
	 * The app version moved too, or `occ upgrade` never runs the import.
	 *
	 * `InitializeSettings` is a `<post-migration>` repair step, and post-migration
	 * steps run only on `occ upgrade` — which is a NO-OP when the app version has
	 * not changed. Both bumps are needed; either alone is a fix that sits on disk.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testTheAppVersionMovedSoTheRepairStepRuns(): void {
		$path = __DIR__ . '/../../appinfo/info.xml';
		$this->assertFileExists($path);

		$source = file_get_contents($path);
		$this->assertIsString($source);

		// Read as TEXT, not through SimpleXML. The first version of this test used
		// `simplexml_load_file()`; it worked locally and returned FALSE on every CI
		// leg, failing the whole suite on `appinfo/info.xml must be readable XML`.
		// The assertion here is about one scalar in a file this repository owns, so
		// it should not depend on an XML extension being present in the runner.
		$this->assertSame(
			1,
			preg_match('#<version>([^<]+)</version>#', $source, $version),
			'appinfo/info.xml must declare a <version>.'
		);

		$this->assertGreaterThan(
			0,
			version_compare(trim($version[1]), '0.4.6'),
			'The app version must be greater than 0.4.6 (the last release with no authorization '
				. 'block), or `occ upgrade` is a no-op and InitializeSettings never re-imports the register.'
		);

		// Positive control on the same reader: the file really was read and matched,
		// so a failure above means "not bumped", never "read an empty document".
		$this->assertStringContainsString('<id>decidiq</id>', $source);
	}//end testTheAppVersionMovedSoTheRepairStepRuns()

	/**
	 * Every schema-level authorization block in the main register and its fragments, by schema name.
	 *
	 * @return array<string,array{block: array<string,mixed>, file: string}> The blocks.
	 */
	private function schemaBlocks(): array {
		$files = array_merge(
			[__DIR__ . '/../../lib/Settings/decidesk_register.json'],
			glob(__DIR__ . '/../../lib/Settings/register.d/*.json') ?: []
		);

		$blocks = [];
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			foreach (($decoded['components']['schemas'] ?? []) as $name => $schema) {
				if (is_array($schema) === false || isset($schema['authorization']) === false) {
					continue;
				}

				$this->assertArrayNotHasKey(
					$name,
					$blocks,
					sprintf('Schema `%s` declares an authorization block in two files; which one wins is not obvious.', $name)
				);
				$blocks[$name] = ['block' => $schema['authorization'], 'file' => basename($file)];
			}
		}

		return $blocks;
	}//end schemaBlocks()

	/**
	 * The schemas some fragment deactivates (`x-openregister.active: false`).
	 *
	 * @return array<int,string> Schema names.
	 */
	private function deactivatedSchemas(): array {
		$names = [];
		foreach (glob(__DIR__ . '/../../lib/Settings/register.d/*.json') ?: [] as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			foreach (($decoded['components']['schemas'] ?? []) as $name => $schema) {
				if (is_array($schema) === true && (($schema['x-openregister']['active'] ?? null) === false)) {
					$names[] = $name;
				}
			}
		}

		return $names;
	}//end deactivatedSchemas()

	/**
	 * Every schema-level block states its write posture on purpose, and the posture matches its class.
	 *
	 * This replaces `testSchemasWithTheirOwnBlockStillDeclareOnlyReads`, which
	 * pinned the defect in decidiq#1269. That test was right about the mechanism
	 * (a schema block SHADOWS the register baseline entirely) and asserted that
	 * every block should therefore name `read` only, reasoning that the blocks
	 * "already fail closed on writes". For a schema the SPA writes through the
	 * object API, failing closed on writes is not a security posture, it is the
	 * outage: a `decidiq-administrators` member got 403 on every Decision update,
	 * and nobody but a Nextcloud superuser could create one.
	 *
	 * What it protected is kept: no block may gain a write by accident, and the
	 * 36-block count still catches schemas that gain or lose a block. The
	 * difference is that each block now belongs to exactly one named class, and
	 * the class decides what it may declare:
	 *
	 *   - RESTATES_THE_BASELINE_WRITES: create, update and delete exactly as the
	 *     register row grants them.
	 *   - SERVICE_OWNED_WRITES_STAY_CLOSED: no write verb, because a decidiq
	 *     service owns the write and its guard must not be bypassable.
	 *   - EvaluationResponse: `create` for authenticated only (anonymity of the
	 *     raw answers, see its _authorizationNote).
	 *   - RETIRED_READ_ONLY: no write verb.
	 *
	 * An unclassified block fails, so a new block forces the decision rather
	 * than inheriting one silently.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-007-a-schema-block-that-narrows-reads-restates-the-writes-the-app-needs
	 */
	public function testEverySchemaBlockDeclaresItsWritesOnPurpose(): void {
		$baseline = $this->registerRow()['authorization'];
		$blocks   = $this->schemaBlocks();

		foreach ($blocks as $name => $entry) {
			$block = $entry['block'];
			$where = sprintf('Schema `%s` (%s)', $name, $entry['file']);

			if (in_array($name, self::RESTATES_THE_BASELINE_WRITES, true) === true) {
				foreach (self::WRITE_ACTIONS as $action) {
					$this->assertArrayHasKey(
						$action,
						$block,
						sprintf(
							'%s must declare `%s`. Its block replaces the register block whole, so an omitted '
								. 'write is DENIED to everyone but the owner and a superuser, and the SPA writes this '
								. 'schema through the object API (decidiq#1269).',
							$where,
							$action
						)
					);
					$this->assertSame(
						$baseline[$action],
						$block[$action],
						sprintf(
							'%s must grant `%s` exactly as the register row does. A narrower list re-creates '
								. 'decidiq#1269 for the groups it drops; a wider one grants what the register never did.',
							$where,
							$action
						)
					);
				}

				continue;
			}

			if (array_key_exists($name, self::SERVICE_OWNED_WRITES_STAY_CLOSED) === true) {
				foreach (self::WRITE_ACTIONS as $action) {
					$this->assertArrayNotHasKey(
						$action,
						$block,
						sprintf(
							'%s must NOT declare `%s`: %s owns this write and runs its own per-object guard. '
								. 'Granting it on the object API lets any member go around that guard.',
							$where,
							$action,
							self::SERVICE_OWNED_WRITES_STAY_CLOSED[$name]
						)
					);
				}

				continue;
			}

			if ($name === 'EvaluationResponse') {
				$this->assertSame(
					['authenticated'],
					$block['create'] ?? null,
					'EvaluationResponse may open `create` to authenticated members and NOTHING wider.'
				);
				$this->assertArrayNotHasKey(
					'update',
					$block,
					'EvaluationResponse keeps `update` closed; the author owns their own response.'
				);
				$this->assertArrayNotHasKey('delete', $block, 'EvaluationResponse keeps `delete` closed outright.');
				continue;
			}

			$this->assertContains(
				$name,
				self::RETIRED_READ_ONLY,
				sprintf(
					'%s declares an authorization block that no class in this test accounts for. A schema block '
						. 'replaces the register block whole and denies every action it omits, so decide its writes '
						. 'on purpose and add it to one of the lists above.',
					$where
				)
			);
			$this->assertContains(
				$name,
				$this->deactivatedSchemas(),
				sprintf('%s is listed as retired, but no fragment sets its x-openregister.active to false.', $where)
			);
			foreach (self::WRITE_ACTIONS as $action) {
				$this->assertArrayNotHasKey(
					$action,
					$block,
					sprintf('%s is retired; nothing may write a new row into it, so it must not declare `%s`.', $where, $action)
				);
			}
		}//end foreach

		// Every listed schema really has a block, so a list entry cannot go stale unnoticed.
		foreach (array_merge(self::RESTATES_THE_BASELINE_WRITES, array_keys(self::SERVICE_OWNED_WRITES_STAY_CLOSED), self::RETIRED_READ_ONLY, ['EvaluationResponse']) as $listed) {
			$this->assertArrayHasKey($listed, $blocks, sprintf('`%s` is classified here but declares no block.', $listed));
		}

		// The count is the positive control: without it the loop above passes
		// vacuously if the schemas move, are renamed, or stop being found at all.
		// 14 restate the baseline writes, 4 are service owned, 1 is
		// EvaluationResponse, 17 are retired. A different number means schemas
		// gained or lost their own block, which changes which ones the register
		// baseline governs.
		$this->assertCount(36, $blocks, 'Expected 36 schema-level authorization blocks.');
	}//end testEverySchemaBlockDeclaresItsWritesOnPurpose()

	/**
	 * A renamed schema keeps the READ rule of the one it replaces.
	 *
	 * 🔴 A RENAME THAT DROPS THE BLOCK PUBLISHES PERSONAL DATA, SILENTLY.
	 *
	 * `PermissionHandler::resolveAuthorization()` falls back to the REGISTER
	 * baseline when a schema declares no block of its own, and that baseline
	 * grants `read` and `list` to `public`. So a disclosure schema that loses its
	 * own block does not fail, does not warn, and does not look different in any
	 * list: it simply becomes readable by anonymous visitors, ignoring the
	 * publication date its own block existed to enforce.
	 *
	 * What a rename must carry is the `read` rule, byte for byte. The write
	 * verbs are a separate decision, pinned by
	 * testEverySchemaBlockDeclaresItsWritesOnPurpose(): the renamed schemas are
	 * live and written through the object API, so they restate the register's
	 * write grants, while their retired predecessors stay read-only. Comparing
	 * whole blocks here would have forced the live schema to stay unwritable
	 * just to match a retired one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integrity-disclosures-in-plain-words/specs/integrity-disclosures-in-plain-words/spec.md#requirement-existing-disclosures-are-carried-across
	 */
	public function testARenamedSchemaKeepsItsPredecessorsReadRule(): void {
		// Every rename this programme has made where the SOURCE carried its own
		// block. Each new entry here is one more schema that cannot silently
		// lose its authorization to a rename.
		$renames = [
			'Nevenfunctie' => 'AncillaryPosition',
			'Geschenk' => 'DeclaredGift',
			'Geheimhouding' => 'ConfidentialityRestriction',
			'GeheimhoudingGrond' => 'ConfidentialityGround',
			'Toezegging' => 'Commitment',
			'TermijnagendaItem' => 'PlannedAgendaItem',
			'Bevoegdheidstoedeling' => 'AuthorityDelegation',
		];

		$blocks = $this->schemaBlocks();

		foreach ($renames as $before => $after) {
			// Not vacuous: if the predecessor stopped declaring a block, this
			// test would otherwise pass by comparing nothing to nothing.
			$this->assertArrayHasKey(
				$before,
				$blocks,
				sprintf('%s must still declare the block %s is asserted to inherit.', $before, $after)
			);

			$this->assertArrayHasKey(
				$after,
				$blocks,
				sprintf(
					'%s declares no authorization block, so it falls back to the register baseline\'s '
						. 'public read, publishing what %s deliberately gated on publicationDate.',
					$after,
					$before
				)
			);

			$this->assertArrayHasKey('read', $blocks[$before]['block'], sprintf('%s must declare a read rule.', $before));
			$this->assertSame(
				$blocks[$before]['block']['read'],
				$blocks[$after]['block']['read'] ?? null,
				sprintf('%s must carry %s\'s read rule unchanged; a rename may not widen who can read.', $after, $before)
			);
		}//end foreach
	}//end testARenamedSchemaKeepsItsPredecessorsReadRule()

	/**
	 * The demo register resolves to the same permissions as the real one.
	 *
	 * `DemoDataService` imports `decidiq_mock_register.json` with `force: true`
	 * into the SAME `decidiq` register. A forced import overwrites every schema
	 * it carries, so a mock schema whose block differs from the real one does
	 * not stay in the demo: it REPLACES the real block on the instance. Before
	 * decidiq#1269 the two files also disagreed on the register row, where the
	 * mock declared no baseline at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-007-a-schema-block-that-narrows-reads-restates-the-writes-the-app-needs
	 */
	public function testTheMockRegisterResolvesToTheSamePermissions(): void {
		$path = __DIR__ . '/../../lib/Settings/decidiq_mock_register.json';
		$mock = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($mock, 'decidiq_mock_register.json must be valid JSON.');

		$this->assertSame(
			$this->registerRow()['authorization'],
			$mock['components']['registers']['decidiq']['authorization'] ?? null,
			'The mock register row must declare the same authorization baseline as decidesk_register.json.'
		);

		$real      = $this->register['components']['schemas'];
		$compared  = 0;
		$mockNames = [];
		foreach (($mock['components']['schemas'] ?? []) as $name => $schema) {
			$mockNames[] = $name;
			$this->assertSame(
				$real[$name]['authorization'] ?? null,
				$schema['authorization'] ?? null,
				sprintf(
					'Mock schema `%s` must carry the same authorization block as decidesk_register.json. A forced '
						. 'demo import writes the mock block over the real one.',
					$name
				)
			);
			if (isset($schema['authorization']) === true) {
				$compared++;
			}
		}

		// Positive control: the mock carries the main file's schemas, and eight of them have a block.
		$this->assertContains('Decision', $mockNames, 'The mock must carry the Decision schema.');
		$this->assertSame(8, $compared, 'Expected 8 schema-level blocks in the mock, the same 8 as decidesk_register.json.');
	}//end testTheMockRegisterResolvesToTheSamePermissions()

	/**
	 * The flow-owned publication fields declare a property-level update rule.
	 *
	 * This replaces `PublicationEligibilityService::guardDirectPublicationWrite()`,
	 * which was removed in #1268 because it had no production caller. The write it
	 * claimed to stop is an OpenRegister object update, which never enters decidiq
	 * PHP, so no imperative guard could ever have run. The declaration below is
	 * read by `PropertyRbacHandler::getUnauthorizedProperties()` on the OR side,
	 * which is the only code on that path.
	 *
	 * What this pins, and what it does NOT:
	 *
	 *   - PINNED: both shipped registers declare an `update` rule naming only
	 *     `decidiq-publication-flow`, a group with no members. Every group the
	 *     register grants `update` (decidiq-administrators, decidesk-administrators)
	 *     is therefore refused a direct write to these two fields.
	 *   - NOT PINNED, and NOT enforceable here: a Nextcloud superuser bypasses
	 *     property authorization unconditionally
	 *     (`PropertyRbacHandler::getUnauthorizedProperties()` returns `[]` for
	 *     `isAdmin()`). The publish endpoint admits only superusers, so the
	 *     legitimate flow keeps working for exactly that reason. Closing the
	 *     superuser gap needs a mechanism OpenRegister does not have yet.
	 *
	 * Both files are checked because a forced demo import writes the mock's
	 * schemas over the real ones (see testTheMockRegisterResolvesToTheSamePermissions),
	 * so asserting on one would leave the other free to drift.
	 *
	 * These rules only bite a principal who may update the Decision at all. Until
	 * decidiq#1269 nobody but the owner and a superuser could, so the rule was
	 * inert for exactly the groups it names. The E2E spec
	 * tests/e2e/workflows/decision-write-authorization.spec.ts proves it now
	 * refuses a decidiq-administrators member who CAN edit the title.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testFlowOwnedPublicationFieldsRefuseADirectUpdate(): void {
		$registers = [
			'decidesk_register.json'    => __DIR__ . '/../../lib/Settings/decidesk_register.json',
			'decidiq_mock_register.json' => __DIR__ . '/../../lib/Settings/decidiq_mock_register.json',
		];

		foreach ($registers as $label => $path) {
			$decoded = json_decode((string)file_get_contents($path), true);
			$this->assertIsArray($decoded, sprintf('%s must be valid JSON.', $label));

			$properties = $decoded['components']['schemas']['Decision']['properties'] ?? null;
			$this->assertIsArray($properties, sprintf('%s must declare Decision properties.', $label));

			foreach (['isPublished', 'publishedAt'] as $field) {
				$this->assertArrayHasKey(
					$field,
					$properties,
					sprintf('%s: Decision must declare the flow-owned field `%s`.', $label, $field)
				);

				$rules = $properties[$field]['authorization']['update'] ?? null;

				$this->assertIsArray(
					$rules,
					sprintf(
						'%s: `%s` must declare authorization.update. Without it '
							. 'PropertyRbacHandler treats the field as following object-level rules, '
							. 'which grant update to both administrator groups.',
						$label,
						$field
					)
				);

				$this->assertSame(
					['decidiq-publication-flow'],
					$rules,
					sprintf(
						'%s: `%s` must grant update to `decidiq-publication-flow` and nothing else. '
							. 'Naming any populated group here reopens the direct write; naming '
							. '`public` or `authenticated` reopens it to everyone.',
						$label,
						$field
					)
				);
			}
		}

	}//end testFlowOwnedPublicationFieldsRefuseADirectUpdate()
}//end class
