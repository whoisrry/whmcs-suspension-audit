<?php
/**
 * Service Suspension Audit - WHMCS Addon Module
 *
 * Menampilkan service Active yang due date-nya sudah lewat atau jatuh tempo
 * dalam N hari ke depan, plus (opsional) service Free Account / One Time.
 *
 * Kompatibel: WHMCS 8.13.x dan 9.x, PHP 8.1 - 8.3.
 * Read-only: tidak mengubah data apa pun dan tidak membuat tabel.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

const SSA_VERSION = '1.0.0';
const SSA_DEFAULT_DAYS = 7;
const SSA_MAX_DAYS = 365;
const SSA_CYCLES_FREE = ['Free Account', 'Free'];
const SSA_CYCLES_ONETIME = ['One Time', 'Onetime'];
const SSA_INVOICE_UNPAID = ['Unpaid', 'Payment Pending', 'Collections'];

function serviceSuspensionAudit_config(): array
{
    return [
        'name' => 'Service Suspension Audit',
        'description' => 'Daftar service Active yang due date-nya sudah lewat atau jatuh tempo dalam N hari, untuk audit suspensi.',
        'version' => SSA_VERSION,
        'author' => 'Cloudata',
        'language' => 'english',
        'fields' => [
            'reminder_days' => [
                'FriendlyName' => 'Reminder Days',
                'Type' => 'text',
                'Size' => '5',
                'Default' => (string) SSA_DEFAULT_DAYS,
                'Description' => 'Jumlah hari ke depan untuk cek due date (0-' . SSA_MAX_DAYS . ')',
            ],
            'show_free' => [
                'FriendlyName' => 'Show Free Account',
                'Type' => 'yesno',
                'Description' => 'Tampilkan service dengan billing cycle Free Account',
            ],
            'show_onetime' => [
                'FriendlyName' => 'Show One Time',
                'Type' => 'yesno',
                'Description' => 'Tampilkan service dengan billing cycle One Time',
            ],
        ],
    ];
}

function serviceSuspensionAudit_activate(): array
{
    return ['status' => 'success', 'description' => 'Service Suspension Audit aktif. Atur akses role di Setup > Addon Modules.'];
}

function serviceSuspensionAudit_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Service Suspension Audit dinonaktifkan.'];
}

/**
 * Normalisasi konfigurasi addon dari $vars.
 *
 * @return array{days:int, showFree:bool, showOnetime:bool}
 */
function serviceSuspensionAudit_settings(array $vars): array
{
    $raw = trim((string) ($vars['reminder_days'] ?? ''));
    $days = ctype_digit($raw) ? (int) $raw : SSA_DEFAULT_DAYS;

    return [
        'days' => max(0, min(SSA_MAX_DAYS, $days)),
        'showFree' => ($vars['show_free'] ?? '') === 'on',
        'showOnetime' => ($vars['show_onetime'] ?? '') === 'on',
    ];
}

/**
 * Ambil service sesuai aturan filter di PLAN.md.
 */
function serviceSuspensionAudit_fetch(int $days, bool $showFree, bool $showOnetime, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $limitDate = date('Y-m-d', strtotime($today . ' +' . $days . ' days'));
    $noDueCycles = array_merge(SSA_CYCLES_FREE, SSA_CYCLES_ONETIME);

    $query = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where('h.domainstatus', 'Active')
        ->where(function ($q) use ($noDueCycles, $limitDate, $showFree, $showOnetime) {
            $q->where(function ($recurring) use ($noDueCycles, $limitDate) {
                $recurring->whereNotIn('h.billingcycle', $noDueCycles)
                    ->whereNotNull('h.nextduedate')
                    ->where('h.nextduedate', '>', '0000-00-00')
                    ->where('h.nextduedate', '<=', $limitDate);
            });
            if ($showFree) {
                $q->orWhereIn('h.billingcycle', SSA_CYCLES_FREE);
            }
            if ($showOnetime) {
                $q->orWhereIn('h.billingcycle', SSA_CYCLES_ONETIME);
            }
        })
        ->select([
            'h.id', 'h.userid', 'h.domain', 'h.billingcycle', 'h.nextduedate', 'h.domainstatus',
            'c.firstname', 'c.lastname', 'c.companyname', 'c.email',
            'p.name as productname',
        ])
        ->orderBy('h.nextduedate', 'asc')
        ->orderBy('h.id', 'asc');

    $rows = [];
    foreach ($query->get() as $row) {
        $row = (array) $row;
        $isNoDue = in_array($row['billingcycle'], $noDueCycles, true);
        $due = $isNoDue ? null : substr((string) $row['nextduedate'], 0, 10);

        if ($isNoDue) {
            $state = 'nodue';
            $daysDiff = null;
        } else {
            $daysDiff = (int) round((strtotime($due) - strtotime($today)) / 86400);
            $state = $daysDiff < 0 ? 'overdue' : 'upcoming';
        }

        $row['due'] = $due;
        $row['state'] = $state;
        $row['days_diff'] = $daysDiff;
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Ambil invoice yang terkait service (tblinvoiceitems type 'Hosting', relid = tblhosting.id,
 * dan tblinvoices.userid = tblhosting.userid).
 * Hasil per service: 'unpaid' = invoice belum lunas, 'latest' = invoice terbaru (fallback).
 *
 * @param int[] $serviceIds
 * @return array<int, array{unpaid: array<int, array>, latest: ?array}>
 */
function serviceSuspensionAudit_fetchInvoices(array $serviceIds): array
{
    $map = [];
    foreach ($serviceIds as $id) {
        $map[(int) $id] = ['unpaid' => [], 'latest' => null];
    }
    if (!$map) {
        return $map;
    }

    foreach (array_chunk(array_keys($map), 500) as $chunk) {
        $items = Capsule::table('tblinvoiceitems as ii')
            ->join('tblinvoices as i', 'i.id', '=', 'ii.invoiceid')
            ->join('tblhosting as h', 'h.id', '=', 'ii.relid')
            // Invoice harus milik client yang sama dengan service (antisipasi service yang pernah dipindah client).
            ->whereColumn('i.userid', 'h.userid')
            ->where('ii.type', 'Hosting')
            ->whereIn('ii.relid', $chunk)
            ->select(['ii.relid', 'i.id', 'i.status', 'i.duedate'])
            ->distinct()
            ->orderBy('i.id', 'desc')
            ->get();

        foreach ($items as $item) {
            $item = (array) $item;
            $sid = (int) $item['relid'];
            $invoice = [
                'id' => (int) $item['id'],
                'status' => (string) $item['status'],
                'duedate' => substr((string) $item['duedate'], 0, 10),
            ];
            if ($map[$sid]['latest'] === null) {
                $map[$sid]['latest'] = $invoice;
            }
            if (in_array($invoice['status'], SSA_INVOICE_UNPAID, true)
                && !isset($map[$sid]['unpaid'][$invoice['id']])) {
                $map[$sid]['unpaid'][$invoice['id']] = $invoice;
            }
        }
    }

    return $map;
}

function serviceSuspensionAudit_invoiceLink(array $invoice, bool $withDue): string
{
    $url = 'invoices.php?action=edit&id=' . $invoice['id'];
    $label = '#' . $invoice['id'] . ' (' . $invoice['status']
        . ($withDue ? ', due ' . serviceSuspensionAudit_formatDate($invoice['duedate']) : '') . ')';

    return '<a href="' . serviceSuspensionAudit_e($url) . '" target="_blank" rel="noopener">'
        . serviceSuspensionAudit_e($label) . '</a>';
}

function serviceSuspensionAudit_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function serviceSuspensionAudit_formatDate(?string $date): string
{
    if ($date === null) {
        return '-';
    }
    if (function_exists('fromMySQLDate')) {
        return (string) fromMySQLDate($date);
    }
    return $date;
}

function serviceSuspensionAudit_output($vars): void
{
    $lang = is_array($vars['_lang'] ?? null) ? $vars['_lang'] : [];
    $t = static function (string $key, string $fallback) use ($lang): string {
        return isset($lang[$key]) ? (string) $lang[$key] : $fallback;
    };
    $e = 'serviceSuspensionAudit_e';

    $settings = serviceSuspensionAudit_settings((array) $vars);

    try {
        $rows = serviceSuspensionAudit_fetch($settings['days'], $settings['showFree'], $settings['showOnetime']);
        $invoices = serviceSuspensionAudit_fetchInvoices(array_column($rows, 'id'));
    } catch (\Throwable $ex) {
        if (function_exists('logActivity')) {
            logActivity('Service Suspension Audit query error: ' . $ex->getMessage());
        }
        echo '<div class="alert alert-danger">' . $e($t('error_query', 'Gagal mengambil data. Detail tercatat di Activity Log.')) . '</div>';
        return;
    }

    $overdue = count(array_filter($rows, static fn ($r) => $r['state'] === 'overdue'));
    $onOff = static fn (bool $v) => $v ? $t('on', 'On') : $t('off', 'Off');

    echo '<style>
        .ssa-table tr.ssa-overdue td{background:#f8d7da;}
        .ssa-summary span{margin-right:15px;}
        .ssa-table .ssa-invoice-latest{color:#777;}
    </style>';

    echo '<p class="ssa-summary">'
        . '<span><strong>' . $e($t('total', 'Total service')) . ':</strong> ' . count($rows) . '</span>'
        . '<span><strong>' . $e($t('overdue_count', 'Lewat due date')) . ':</strong> ' . $overdue . '</span>'
        . '<span><strong>' . $e($t('reminder_days', 'Reminder days')) . ':</strong> ' . $settings['days'] . '</span>'
        . '<span><strong>Free Account:</strong> ' . $e($onOff($settings['showFree'])) . '</span>'
        . '<span><strong>One Time:</strong> ' . $e($onOff($settings['showOnetime'])) . '</span>'
        . '</p>';

    if (!$rows) {
        echo '<div class="alert alert-success">' . $e($t('empty', 'Tidak ada service yang perlu diaudit.')) . '</div>';
        return;
    }

    $headers = [
        $t('col_id', 'ID'), $t('col_client', 'Client'), $t('col_email', 'Email'),
        $t('col_product', 'Produk'), $t('col_cycle', 'Billing Cycle'), $t('col_domain', 'Domain'),
        $t('col_due', 'Due Date'), $t('col_status', 'Status'), $t('col_invoice', 'Invoice'),
    ];

    echo '<div class="table-responsive"><table class="table table-condensed table-bordered ssa-table"><thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . $e($h) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $client = trim($r['firstname'] . ' ' . $r['lastname']);
        if ($r['companyname'] !== null && $r['companyname'] !== '') {
            $client .= ' (' . $r['companyname'] . ')';
        }

        switch ($r['state']) {
            case 'overdue':
                $status = sprintf($t('status_overdue', 'Lewat %d hari'), abs($r['days_diff']));
                break;
            case 'upcoming':
                $status = $r['days_diff'] === 0
                    ? $t('status_today', 'Due hari ini')
                    : sprintf($t('status_upcoming', 'Due dalam %d hari'), $r['days_diff']);
                break;
            default:
                $status = $t('status_nodue', 'Tanpa due date');
        }

        $serviceUrl = 'clientsservices.php?userid=' . (int) $r['userid'] . '&id=' . (int) $r['id'];
        $clientUrl = 'clientssummary.php?userid=' . (int) $r['userid'];

        $inv = $invoices[(int) $r['id']] ?? ['unpaid' => [], 'latest' => null];
        if ($inv['unpaid']) {
            $invoiceHtml = implode('<br>', array_map(
                static fn ($i) => serviceSuspensionAudit_invoiceLink($i, true),
                $inv['unpaid']
            ));
        } elseif ($inv['latest'] !== null) {
            $invoiceHtml = '<span class="ssa-invoice-latest">' . $e($t('invoice_latest', 'Terakhir')) . ': '
                . serviceSuspensionAudit_invoiceLink($inv['latest'], false) . '</span>';
        } else {
            $invoiceHtml = '-';
        }

        echo '<tr' . ($r['state'] === 'overdue' ? ' class="ssa-overdue"' : '') . '>'
            . '<td><a class="ssa-service-link" href="' . $e($serviceUrl) . '" target="_blank" rel="noopener" title="'
            . $e($t('open_service', 'Buka service')) . '">#' . (int) $r['id'] . '</a></td>'
            . '<td><a href="' . $e($clientUrl) . '">' . $e($client) . '</a></td>'
            . '<td>' . $e($r['email']) . '</td>'
            . '<td>' . $e($r['productname'] ?? '-') . '</td>'
            . '<td>' . $e($r['billingcycle']) . '</td>'
            . '<td>' . $e($r['domain'] !== '' ? $r['domain'] : '-') . '</td>'
            . '<td>' . $e(serviceSuspensionAudit_formatDate($r['due'])) . '</td>'
            . '<td>' . $e($status) . '</td>'
            . '<td>' . $invoiceHtml . '</td>'
            . '</tr>';
    }

    echo '</tbody></table></div>';
}
