<?php
/**
 * CEISA 4.0 - ERP Manager (ErpManager.php)
 * Central Factory & Dispatcher untuk Integrasi Modular ERP.
 */

require_once __DIR__ . '/ErpBaseDriver.php';
require_once __DIR__ . '/drivers/FramasDriver.php';
require_once __DIR__ . '/drivers/SapDriver.php';
require_once __DIR__ . '/drivers/HeesungDriver.php';
require_once __DIR__ . '/drivers/OdooDriver.php';
require_once __DIR__ . '/drivers/GsoftDriver.php';
require_once __DIR__ . '/drivers/AccurateDriver.php';
require_once __DIR__ . '/drivers/TpberpDriver.php';
require_once __DIR__ . '/drivers/CustomDriver.php';

class ErpManager
{
    protected static $driverClasses = [
        'FRAMAS'   => FramasDriver::class,
        'SAP'      => SapDriver::class,
        'HEESUNG'  => HeesungDriver::class,
        'ODOO'     => OdooDriver::class,
        'GSOFT'    => GsoftDriver::class,
        'ACCURATE' => AccurateDriver::class,
        'TPBERP'   => TpberpDriver::class,
        'CUSTOM'   => CustomDriver::class,
    ];

    /**
     * Dapatkan daftar driver yang tersedia di sistem
     */
    public static function listDrivers(): array
    {
        return [
            ['code' => 'FRAMAS',   'name' => 'FRAMAS (Winline ERP)', 'desc' => 'Delivery Note DN%, Sales Order & Purchase Order'],
            ['code' => 'SAP',      'name' => 'SAP ECC / S/4HANA',     'desc' => 'SD Billing Document, Outbound Delivery, MM Purchase Order'],
            ['code' => 'HEESUNG',  'name' => 'HEESUNG Electronics',   'desc' => 'Invoicing, Delivery Order, Parts Import/Export'],
            ['code' => 'ODOO',     'name' => 'Odoo ERP (Community / Enterprise)', 'desc' => 'Invoicing (account.move) & Delivery (stock.picking)'],
            ['code' => 'GSOFT',    'name' => 'GSOFT (Manufacturing & Garment ERP)', 'desc' => 'Delivery Order (DO), Commercial Invoice, Goods Receipt (GRN)'],
            ['code' => 'ACCURATE', 'name' => 'ACCURATE (Accounting & Online ERP)', 'desc' => 'Faktur Penjualan (SI), Pengiriman Pesanan (DO), Penerimaan Barang'],
            ['code' => 'TPBERP',   'name' => 'TPBERP (Kawasan Berikat ERP)', 'desc' => 'Staging khusus IT Inventory dan Dokumen Pabean (BC 2.3, 2.5, 4.0, 2.7)'],
            ['code' => 'CUSTOM',   'name' => 'CUSTOM (Query Pengguna)', 'desc' => 'Konektor query kustom yang dapat dimodifikasi sendiri'],
        ];
    }

    /**
     * Ambil instance driver yang sesuai untuk perusahaan
     */
    public static function getDriver(mysqli $db, ?string $cmpId = '', ?string $erpNameOverride = null): ?ErpBaseDriver
    {
        $company = [];
        if (!empty($cmpId)) {
            $escCmp = $db->real_escape_string($cmpId);
            $q = $db->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
            if ($q && $r = $q->fetch_assoc()) {
                $company = $r;
            }
        }

        // Tentukan nama ERP
        $erpName = $erpNameOverride;
        if (empty($erpName)) {
            $erpName = trim($company['ERP_Name'] ?? '');
        }

        $cleanErpName = strtoupper(trim($erpName));
        if (empty($cleanErpName) || $cleanErpName === '-' || $cleanErpName === 'NONE') {
            return null;
        }

        $driverClass = self::$driverClasses[$cleanErpName] ?? CustomDriver::class;
        return new $driverClass($company, $db);
    }
}
