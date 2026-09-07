// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for decidiq (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import Account from 'vue-material-design-icons/Account.vue'
import AccountArrowRightOutline from 'vue-material-design-icons/AccountArrowRightOutline.vue'
import AccountBoxOutline from 'vue-material-design-icons/AccountBoxOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountKeyOutline from 'vue-material-design-icons/AccountKeyOutline.vue'
import AccountMinusOutline from 'vue-material-design-icons/AccountMinusOutline.vue'
import AccountMultiplePlusOutline from 'vue-material-design-icons/AccountMultiplePlusOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountQuestionOutline from 'vue-material-design-icons/AccountQuestionOutline.vue'
import AccountRemoveOutline from 'vue-material-design-icons/AccountRemoveOutline.vue'
import AccountTieOutline from 'vue-material-design-icons/AccountTieOutline.vue'
import AccountVoice from 'vue-material-design-icons/AccountVoice.vue'
import AlertOctagonOutline from 'vue-material-design-icons/AlertOctagonOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import Api from 'vue-material-design-icons/Api.vue'
import BellCogOutline from 'vue-material-design-icons/BellCogOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import BriefcaseAccountOutline from 'vue-material-design-icons/BriefcaseAccountOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import BullhornOutline from 'vue-material-design-icons/BullhornOutline.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarAccountOutline from 'vue-material-design-icons/CalendarAccountOutline.vue'
import CalendarCheckOutline from 'vue-material-design-icons/CalendarCheckOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import CalendarTextOutline from 'vue-material-design-icons/CalendarTextOutline.vue'
import CardAccountDetailsOutline from 'vue-material-design-icons/CardAccountDetailsOutline.vue'
import CartOutline from 'vue-material-design-icons/CartOutline.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardTextClockOutline from 'vue-material-design-icons/ClipboardTextClockOutline.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CommentAccountOutline from 'vue-material-design-icons/CommentAccountOutline.vue'
import CommentCheckOutline from 'vue-material-design-icons/CommentCheckOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import CommentQuestionOutline from 'vue-material-design-icons/CommentQuestionOutline.vue'
import CommentQuoteOutline from 'vue-material-design-icons/CommentQuoteOutline.vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import Email from 'vue-material-design-icons/Email.vue'
import EmailArrowLeftOutline from 'vue-material-design-icons/EmailArrowLeftOutline.vue'
import EmailArrowRightOutline from 'vue-material-design-icons/EmailArrowRightOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileLockOutline from 'vue-material-design-icons/FileLockOutline.vue'
import FileReplaceOutline from 'vue-material-design-icons/FileReplaceOutline.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import ForumOutline from 'vue-material-design-icons/ForumOutline.vue'
import Gauge from 'vue-material-design-icons/Gauge.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import LibraryOutline from 'vue-material-design-icons/LibraryOutline.vue'
import Lightbulb from 'vue-material-design-icons/Lightbulb.vue'
import LightbulbOnOutline from 'vue-material-design-icons/LightbulbOnOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import Microphone from 'vue-material-design-icons/Microphone.vue'
import NotebookOutline from 'vue-material-design-icons/NotebookOutline.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Package from 'vue-material-design-icons/Package.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import ReceiptOutline from 'vue-material-design-icons/ReceiptOutline.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import SeatOutline from 'vue-material-design-icons/SeatOutline.vue'
import SetMerge from 'vue-material-design-icons/SetMerge.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import ShieldKeyOutline from 'vue-material-design-icons/ShieldKeyOutline.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import TableColumn from 'vue-material-design-icons/TableColumn.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import TargetVariant from 'vue-material-design-icons/TargetVariant.vue'
import ThumbUpOutline from 'vue-material-design-icons/ThumbUpOutline.vue'
import Timeline from 'vue-material-design-icons/Timeline.vue'
import TransitConnectionVariant from 'vue-material-design-icons/TransitConnectionVariant.vue'
import TrayFull from 'vue-material-design-icons/TrayFull.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import VoteOutline from 'vue-material-design-icons/VoteOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'

export default {
	Account,
	AccountArrowRightOutline,
	AccountBoxOutline,
	AccountGroup,
	AccountGroupOutline,
	AccountKeyOutline,
	AccountMinusOutline,
	AccountMultiplePlusOutline,
	AccountOutline,
	AccountPlusOutline,
	AccountQuestionOutline,
	AccountRemoveOutline,
	AccountTieOutline,
	AccountVoice,
	AlertOctagonOutline,
	AlertOutline,
	Api,
	BellCogOutline,
	BellOutline,
	BookOpenVariant,
	BookOpenVariantOutline,
	Briefcase,
	BriefcaseAccountOutline,
	BriefcaseOutline,
	BullhornOutline,
	Calendar,
	CalendarAccountOutline,
	CalendarCheckOutline,
	CalendarClock,
	CalendarClockOutline,
	CalendarMonthOutline,
	CalendarTextOutline,
	CardAccountDetailsOutline,
	CartOutline,
	Cash,
	CashMultiple,
	ChartBar,
	ChartBoxOutline,
	Check,
	CheckCircleOutline,
	CheckboxMarkedOutline,
	ClipboardCheckOutline,
	ClipboardListOutline,
	ClipboardList,
	ClipboardTextClockOutline,
	ClipboardTextOutline,
	CloudUploadOutline,
	Cog,
	CogOutline,
	CommentAccountOutline,
	CommentOutline,
	CommentCheckOutline,
	CommentQuestionOutline,
	CommentQuoteOutline,
	CommentTextOutline,
	CurrencyEur,
	DatabaseOutline,
	Domain,
	Earth,
	Email,
	EmailArrowLeftOutline,
	EmailArrowRightOutline,
	EmailOutline,
	EyeOutline,
	FileChartOutline,
	FileDocument,
	FileDocumentCheckOutline,
	FileDocumentMultipleOutline,
	FileDocumentOutline,
	FileLockOutline,
	FileReplaceOutline,
	FileSign,
	FolderOutline,
	FormatListChecks,
	FormatListBulleted,
	FormatListNumbered,
	ForumOutline,
	Gauge,
	Gavel,
	GiftOutline,
	HandshakeOutline,
	History,
	LibraryOutline,
	Lightbulb,
	LightbulbOnOutline,
	LinkVariant,
	MapMarkerPath,
	MessageTextOutline,
	Microphone,
	NoteTextOutline,
	NotebookOutline,
	OfficeBuilding,
	OfficeBuildingOutline,
	Package,
	PackageVariantClosed,
	ReceiptOutline,
	ScaleBalance,
	SeatOutline,
	SetMerge,
	ShieldCheckOutline,
	ShieldKeyOutline,
	ShieldLockOutline,
	Sitemap,
	Star,
	StoreOutline,
	SwapHorizontal,
	TableColumn,
	TagOutline,
	TargetVariant,
	ThumbUpOutline,
	Timeline,
	TransitConnectionVariant,
	TrayFull,
	ViewDashboardOutline,
	VoteOutline,
	Web,
}
