<script setup>
/**
 * AppIcon — ikon lucide berdasarkan NAMA (backend/ProcessFlow mengirim nama, bukan emoji).
 * Dekoratif secara default (aria-hidden). Beri `label` bila ikon berdiri sendiri (tombol ikon).
 */
import { computed } from 'vue'
import {
    LayoutDashboard, Boxes, Package, PackageOpen, Workflow, FlaskConical, Ship, FileText, Settings, Users,
    Building2, Radio, Video, Droplets, Flame, Microscope, Warehouse, ScanLine, Tag, Tags, Nfc, CircleCheck,
    CircleX, TriangleAlert, Info, Loader2, Plus, Search, ChevronDown, ChevronRight, ChevronLeft, X, Menu, Sun,
    Moon, Languages, LogOut, Undo2, Ban, RefreshCw, KeyRound, Wifi, WifiOff, History, LogIn, ArrowDownToLine,
    ArrowUpFromLine, Smartphone, Download, Printer, Pencil, Trash2, Eye, EyeOff, CheckCheck, Clock, Camera,
    CameraOff, Truck, PanelLeftClose, PanelLeftOpen, Activity, ShieldCheck, Cpu, Usb, Keyboard, QrCode, Copy,
    Check, Lock, Mail, BadgeCheck, CircleDashed, Hourglass, Layers, Scale, Factory, MapPin, Bell, CircleHelp,
    ExternalLink, Power, Zap, CircleAlert, OctagonAlert, ScanSearch, RadioTower, Barcode, Monitor, SlidersHorizontal,
    Antenna, Gauge, Mountain, User, Globe, Radiation, Pickaxe, Gem, ClipboardList, Lightbulb, Wrench, Dna, Link, Circle,
} from 'lucide-vue-next'

const MAP = {
    'layout-dashboard': LayoutDashboard, boxes: Boxes, package: Package, 'package-open': PackageOpen, workflow: Workflow,
    'flask-conical': FlaskConical, ship: Ship, 'file-text': FileText, settings: Settings, users: Users,
    'building-2': Building2, radio: Radio, video: Video, droplets: Droplets, flame: Flame, microscope: Microscope,
    warehouse: Warehouse, 'scan-line': ScanLine, tag: Tag, tags: Tags, nfc: Nfc, 'circle-check': CircleCheck,
    'circle-x': CircleX, 'triangle-alert': TriangleAlert, info: Info, loader: Loader2, plus: Plus, search: Search,
    'chevron-down': ChevronDown, 'chevron-right': ChevronRight, 'chevron-left': ChevronLeft, x: X, menu: Menu, sun: Sun,
    moon: Moon, languages: Languages, 'log-out': LogOut, 'undo-2': Undo2, ban: Ban, 'refresh-cw': RefreshCw,
    'key-round': KeyRound, wifi: Wifi, 'wifi-off': WifiOff, history: History, 'log-in': LogIn,
    'arrow-down-to-line': ArrowDownToLine, 'arrow-up-from-line': ArrowUpFromLine, smartphone: Smartphone,
    download: Download, printer: Printer, pencil: Pencil, 'trash-2': Trash2, eye: Eye, 'eye-off': EyeOff,
    'check-check': CheckCheck, clock: Clock, camera: Camera, 'camera-off': CameraOff, truck: Truck,
    'panel-left-close': PanelLeftClose, 'panel-left-open': PanelLeftOpen, activity: Activity,
    'shield-check': ShieldCheck, cpu: Cpu, usb: Usb, keyboard: Keyboard, 'qr-code': QrCode, copy: Copy, check: Check,
    lock: Lock, mail: Mail, 'badge-check': BadgeCheck, 'circle-dashed': CircleDashed, hourglass: Hourglass,
    layers: Layers, scale: Scale, factory: Factory, 'map-pin': MapPin, bell: Bell, 'circle-help': CircleHelp,
    'external-link': ExternalLink, power: Power, zap: Zap, 'circle-alert': CircleAlert, 'octagon-alert': OctagonAlert,
    'scan-search': ScanSearch, 'radio-tower': RadioTower, barcode: Barcode, monitor: Monitor,
    'sliders-horizontal': SlidersHorizontal, antenna: Antenna, gauge: Gauge, mountain: Mountain,
    user: User, globe: Globe, radiation: Radiation, pickaxe: Pickaxe, gem: Gem, 'clipboard-list': ClipboardList,
    lightbulb: Lightbulb, wrench: Wrench, dna: Dna, link: Link, circle: Circle,
}

const props = defineProps({
    name:  { type: String, required: true },
    size:  { type: [Number, String], default: 18 },
    label: { type: String, default: null },
    spin:  { type: Boolean, default: false },
})

const component = computed(() => MAP[props.name] ?? CircleHelp)
</script>

<template>
    <component :is="component" :size="size" :stroke-width="1.75" :class="['flex-shrink-0', spin ? 'animate-spin' : '']"
               :aria-hidden="label ? undefined : 'true'" :aria-label="label || undefined" :role="label ? 'img' : undefined" />
</template>
