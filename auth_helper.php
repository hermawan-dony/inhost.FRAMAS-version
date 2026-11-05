<?php
/**
 * CEISA 4.0 - Sistem Otoritas & Hak Akses Pengguna (auth_helper.php)
 * Mengelola 11 Role Standar, Otoritas Menu (Save / Full Access vs Read Only),
 * Hak Akses Operasional Khusus (Special Permissions), dan Pembatasan Sesi Multi-Tenant.
 */

if (!function_exists('ceisa_get_all_roles')) {

    /**
     * Definisi 11 Role Standar Sistem
     */
    function ceisa_get_all_roles() {
        return [
            'demo' => [
                'name'        => 'Demo User',
                'badge'       => 'DEMO',
                'badge_color' => '#8c8c8c',
                'bg_color'    => '#f5f5f5',
                'icon'        => '🛡️',
                'desc'        => 'Akses Baca Saja (Read Only) di seluruh menu tanpa izin simpan/edit/hapus.',
                'level'       => 4
            ],
            'purchase' => [
                'name'        => 'Purchase',
                'badge'       => 'PURCHASE',
                'badge_color' => '#1890ff',
                'bg_color'    => '#e6f7ff',
                'icon'        => '🛒',
                'desc'        => 'Staff Pengadaan/Pembelian: Entri PR, PO, PI, PRT, Rekanan Vendor, dan Baca Master Barang.',
                'level'       => 3
            ],
            'sales' => [
                'name'        => 'Sales',
                'badge'       => 'SALES',
                'badge_color' => '#10b981',
                'bg_color'    => '#e6ffed',
                'icon'        => '💼',
                'desc'        => 'Staff Penjualan: Entri SO, SI, SR, Buyer, Import SO XLS, dan Baca DO & Master Barang.',
                'level'       => 3
            ],
            'production' => [
                'name'        => 'Production',
                'badge'       => 'PRODUCTION',
                'badge_color' => '#ec4899',
                'bg_color'    => '#fdf2f8',
                'icon'        => '🏭',
                'desc'        => 'Staff Produksi/PPIC: Work Order, Issue BOM & FG/WIP, Scrap, Disposal, Traceability.',
                'level'       => 3
            ],
            'warehouse' => [
                'name'        => 'Warehouse',
                'badge'       => 'WAREHOUSE',
                'badge_color' => '#f59e0b',
                'bg_color'    => '#fffbe6',
                'icon'        => '📦',
                'desc'        => 'Staff Gudang: Receive Item/GRN, Delivery Order, Mutasi BC27, dan Spesial Akses Post IT Inv.',
                'level'       => 3
            ],
            'exim' => [
                'name'        => 'Exim',
                'badge'       => 'EXIM',
                'badge_color' => '#0284c7',
                'bg_color'    => '#e0f2fe',
                'icon'        => '🚢',
                'desc'        => 'Staff Ekspor-Impor: Dokumen Pabean, Manifes, Tarif & Jaminan, Spesial Transfer CEISA.',
                'level'       => 2
            ],
            'accounting' => [
                'name'        => 'Accounting',
                'badge'       => 'ACCOUNTING',
                'badge_color' => '#8b5cf6',
                'bg_color'    => '#f3e8ff',
                'icon'        => '💰',
                'desc'        => 'Staff Akuntansi & Pajak: Hutang/Piutang, Jurnal Voucher, COA, Fixed Assets, Coretax XML.',
                'level'       => 2
            ],
            'admin_inventory' => [
                'name'        => 'Admin Inventory',
                'badge'       => 'ADMIN INV',
                'badge_color' => '#d97706',
                'bg_color'    => '#fef3c7',
                'icon'        => '🏷️',
                'desc'        => 'Administrator Persediaan: Master Barang, BOM, Stok Awal Tahun, Opname, Approval Unposting.',
                'level'       => 2
            ],
            'bc' => [
                'name'        => 'Beacukai',
                'badge'       => 'BEACUKAI',
                'badge_color' => '#d46b08',
                'bg_color'    => '#fff7e6',
                'icon'        => '👮',
                'desc'        => 'Pejabat Pengawas Bea Cukai: Akses Khusus Laporan IT Inventory dan Dokumen Pabean (Read Only).',
                'level'       => 4
            ],
            'admin' => [
                'name'        => 'Administrator Perusahaan',
                'badge'       => 'ADMIN CORP',
                'badge_color' => '#096dd9',
                'bg_color'    => '#bae7ff',
                'icon'        => '🏢',
                'desc'        => 'Akses Penuh Tenant: Kelola seluruh modul, user perusahaan, role override, dan pengaturan.',
                'level'       => 1
            ],
            'superadmin' => [
                'name'        => 'Super Admin',
                'badge'       => 'SUPER ADMIN',
                'badge_color' => '#722ed1',
                'bg_color'    => '#f9f0ff',
                'icon'        => '👑',
                'desc'        => 'Otoritas Global Penuh lintas semua perusahaan (Superuser Utama DP).',
                'level'       => 0
            ]
        ];
    }

    /**
     * Definisi Akses Khusus Operasional (Special Permissions)
     */
    function ceisa_get_special_permissions_def() {
        return [
            'can_post_it_inventory' => [
                'title' => 'Post IT Inventory saat RI & Delivery',
                'desc'  => 'Izin mengeksekusi posting saldo IT Inventory saat Receive Item (GRN) dan Delivery Order (DO)',
                'icon'  => '📥'
            ],
            'can_transfer_ceisa' => [
                'title' => 'Transfer Data ke Portal CEISA 4.0',
                'desc'  => 'Izin mengeksekusi pengiriman dan sinkronisasi host-to-host dokumen ke gateway CEISA 4.0',
                'icon'  => '⚡'
            ],
            'can_access_ceisa_dashboard' => [
                'title' => 'Buka Dashboard Monitoring CEISA 4.0',
                'desc'  => 'Izin mengakses pemantauan real-time status respon dan billing dokumen pabean',
                'icon'  => '🖥️'
            ],
            'can_unposting_approval' => [
                'title' => 'Approval Revisi / UnPosting IT Inventory',
                'desc'  => 'Izin menyetujui pembatalan / revisi transaksi yang telah terposting ke IT Inventory',
                'icon'  => '✔️'
            ]
        ];
    }

    /**
     * Definisi Lengkap Modul Menu Aplikasi
     */
    function ceisa_get_all_menus() {
        return [
            // DOKUMEN PABEAN
            'daftar_dokumen'  => ['title' => 'Dashboard Ceisa 4.0', 'icon' => '🖥️', 'group' => 'Dokumen Pabean'],
            'transfer'        => ['title' => 'Data Host to Host', 'icon' => '⊞', 'group' => 'Dokumen Pabean'],
            'editor'          => ['title' => 'Form Editor Dokumen', 'icon' => '✏️', 'group' => 'Dokumen Pabean'],
            'import'          => ['title' => 'Import Excel Dokumen', 'icon' => '📊', 'group' => 'Dokumen Pabean'],
            'load_erp_odoo'   => ['title' => 'Load ERP (Odoo)', 'icon' => '🔌', 'group' => 'Dokumen Pabean'],
            'manifest'        => ['title' => 'Manifes Online (BC 1.1)', 'icon' => '🚢', 'group' => 'Dokumen Pabean'],
            'dokap'           => ['title' => 'Upload Dokap Pabean', 'icon' => '📎', 'group' => 'Dokumen Pabean'],
            'tracking'        => ['title' => 'Tracking Respon Pabean', 'icon' => '🔍', 'group' => 'Dokumen Pabean'],

            // DATA MASTER & REFERENSI
            'inv_material'       => ['title' => 'Master Data Barang', 'icon' => '🏷️', 'group' => 'Data Master'],
            'inv_koreksi_klas'   => ['title' => 'Koreksi KLAS IT Inv', 'icon' => '🔄', 'group' => 'Data Master'],
            'inv_bom'            => ['title' => 'Master BOM', 'icon' => '🧩', 'group' => 'Data Master'],
            'inv_tarif'          => ['title' => 'Master Tarif BM dari HS', 'icon' => '📑', 'group' => 'Referensi'],
            'inv_jaminan'        => ['title' => 'Master Jaminan', 'icon' => '🛡️', 'group' => 'Data Master'],
            'referensi'          => ['title' => 'Master Data Referensi', 'icon' => '📚', 'group' => 'Referensi'],
            'erp_buyer_supplier' => ['title' => 'Buyer & Supplier (Mitra)', 'icon' => '🤝', 'group' => 'Data Master'],
            'erp_dept'           => ['title' => 'Departemen', 'icon' => '🏢', 'group' => 'Data Master'],
            'erp_satuan'         => ['title' => 'Satuan & Kemasan', 'icon' => '📦', 'group' => 'Data Master'],
            'erp_kurs'           => ['title' => 'Mata Uang & Kurs', 'icon' => '💱', 'group' => 'Referensi'],
            'erp_term'           => ['title' => 'Termin Pembayaran', 'icon' => '⏱️', 'group' => 'Referensi'],
            'erp_jasakirim'      => ['title' => 'Jasa Pengiriman', 'icon' => '🚚', 'group' => 'Referensi'],
            'erp_grup_mat'       => ['title' => 'Grup Material', 'icon' => '📁', 'group' => 'Data Master'],
            'erp_grup_scrap'     => ['title' => 'Grup Scrap', 'icon' => '🗑️', 'group' => 'Data Master'],
            'erp_tax_rate'       => ['title' => 'Rate Pajak (KMK)', 'icon' => '📈', 'group' => 'Referensi'],
            'erp_bi_rate'        => ['title' => 'Rate Bank Indonesia', 'icon' => '🏛️', 'group' => 'Referensi'],

            // PURCHASING & SALES
            'pur_req'         => ['title' => 'Purchase Requisition (PR)', 'icon' => '📝', 'group' => 'Procurement & Purchasing'],
            'pur_po'          => ['title' => 'Purchase Order (PO)', 'icon' => '🛒', 'group' => 'Procurement & Purchasing'],
            'pur_grn'         => ['title' => 'Receive Item (RI / GRN)', 'icon' => '📥', 'group' => 'Procurement & Purchasing'],
            'pur_pi'          => ['title' => 'Purchase Invoice (PI)', 'icon' => '🧾', 'group' => 'Procurement & Purchasing'],
            'pur_pr'          => ['title' => 'Purchase Return (PRT)', 'icon' => '↩️', 'group' => 'Procurement & Purchasing'],
            'sale_so_import'  => ['title' => 'Import SO dari XLS', 'icon' => '📋', 'group' => 'Sales & Delivery'],
            'sale_so'         => ['title' => 'Sales Order (SO)', 'icon' => '📄', 'group' => 'Sales & Delivery'],
            'sale_do'         => ['title' => 'Delivery Order (DO)', 'icon' => '🚚', 'group' => 'Sales & Delivery'],
            'sale_si'         => ['title' => 'Sales Invoice (SI)', 'icon' => '💳', 'group' => 'Sales & Delivery'],
            'sale_sr'         => ['title' => 'Sales Return (SR)', 'icon' => '🔙', 'group' => 'Sales & Delivery'],

            // SCM & PRODUKSI
            'scm_mr'          => ['title' => 'Permintaan Barang (BPPB)', 'icon' => '📋', 'group' => 'Produksi & Mutasi'],
            'scm_wo'          => ['title' => 'Work Order (WO)', 'icon' => '⚙️', 'group' => 'Produksi & Mutasi'],
            'scm_issue_bom'   => ['title' => 'Issue Produksi (BOM)', 'icon' => '📤', 'group' => 'Produksi & Mutasi'],
            'scm_issue_fgwip' => ['title' => 'Issue Produksi (FG/WIP)', 'icon' => '🏭', 'group' => 'Produksi & Mutasi'],
            'scm_scrap'       => ['title' => 'Entri Scrap', 'icon' => '🗑️', 'group' => 'Produksi & Mutasi'],
            'scm_disposal'    => ['title' => 'Disposal Barang', 'icon' => '♻️', 'group' => 'Produksi & Mutasi'],
            'scm_trace_bb'    => ['title' => 'Traceability Bahan Baku', 'icon' => '🔍', 'group' => 'Produksi & Mutasi'],
            'inv_stock_transfer' => ['title' => 'Mutasi Produksi', 'icon' => '🔄', 'group' => 'Produksi & Mutasi'],

            // WAREHOUSE & INVENTORY
            'inv_qr_label'           => ['title' => 'Cetak Label QR & Lot Barcode', 'icon' => '🏷️', 'group' => 'Transaksi Warehouse'],
            'inv_warehouse_in'       => ['title' => 'Warehouse - Incoming', 'icon' => '📥', 'group' => 'Transaksi Warehouse'],
            'inv_warehouse_delivery' => ['title' => 'Warehouse - Delivery', 'icon' => '🚚', 'group' => 'Transaksi Warehouse'],
            'inv_transfer_bc27'      => ['title' => 'Transfer BC27 OUT-->IN', 'icon' => '🔄', 'group' => 'Transaksi Warehouse'],
            'inv_scan27'             => ['title' => 'Import BC 2.7 IN & Post', 'icon' => '📲', 'group' => 'Transaksi Warehouse'],
            'inv_stok_opname'        => ['title' => 'Stok Opname', 'icon' => '📋', 'group' => 'Modul Inventory'],
            'scm_rekap_so'           => ['title' => 'Rekap Stok Opname', 'icon' => '📊', 'group' => 'Modul Inventory'],
            'it_inventory'           => ['title' => 'Laporan IT Inventory (1A-1D)', 'icon' => '📊', 'group' => 'Modul Inventory'],

            // ADMIN INVENTORY
            'inv_unposting_appv' => ['title' => 'Approval Revisi / UnPosting', 'icon' => '✔️', 'group' => 'Admin Inventory'],
            'inv_stok_awal'      => ['title' => 'Set Stok Awal Tahun', 'icon' => '📖', 'group' => 'Admin Inventory'],
            'inv_adjust_spp'     => ['title' => 'Adjust SPP', 'icon' => '⚡', 'group' => 'Admin Inventory'],

            // ACCOUNTING & KEUANGAN
            'fin_gl'              => ['title' => 'Daftar Akun (COA)', 'icon' => '📑', 'group' => 'Accounting & Keuangan'],
            'act_kodebantu'       => ['title' => 'Kode Pembantu Subsidiary', 'icon' => '📒', 'group' => 'Accounting & Keuangan'],
            'act_konfig'          => ['title' => 'Konfigurasi Akun', 'icon' => '⚙️', 'group' => 'Accounting & Keuangan'],
            'act_fa'              => ['title' => 'Fixed Assets & Depresiasi', 'icon' => '🏢', 'group' => 'Accounting & Keuangan'],
            'act_pay'             => ['title' => 'Bayar Hutang (AP)', 'icon' => '💸', 'group' => 'Accounting & Keuangan'],
            'act_rcv'             => ['title' => 'Terima Piutang (AR)', 'icon' => '💰', 'group' => 'Accounting & Keuangan'],
            'fin_cash_bank'       => ['title' => 'Entri Voucher Jurnal', 'icon' => '📖', 'group' => 'Accounting & Keuangan'],
            'act_jurnal_beli'     => ['title' => 'Jurnal Pembelian', 'icon' => '📘', 'group' => 'Accounting & Keuangan'],
            'act_jurnal_jual'     => ['title' => 'Jurnal Penjualan', 'icon' => '📗', 'group' => 'Accounting & Keuangan'],
            'act_jurnal_umum'     => ['title' => 'Jurnal Umum', 'icon' => '📙', 'group' => 'Accounting & Keuangan'],
            'act_jurnal_kas_bank' => ['title' => 'Jurnal Kas / Bank', 'icon' => '📕', 'group' => 'Accounting & Keuangan'],
            'act_jurnal_mutasi'   => ['title' => 'Jurnal Mutasi', 'icon' => '📓', 'group' => 'Accounting & Keuangan'],
            'fin_ap_ar'           => ['title' => 'Laporan Hutang & Piutang', 'icon' => '⚖️', 'group' => 'Accounting & Keuangan'],
            'act_lap_keu'         => ['title' => 'Laporan Keuangan (HPP/LR/Neraca)', 'icon' => '📊', 'group' => 'Accounting & Keuangan'],
            'act_lap_multi'       => ['title' => 'Laporan Multi Periode', 'icon' => '📈', 'group' => 'Accounting & Keuangan'],
            'fin_faktur'          => ['title' => 'e-Faktur Coretax XML', 'icon' => '📑', 'group' => 'Accounting & Keuangan'],
            'fin_vat_out'         => ['title' => 'e-Faktur Import VAT Out', 'icon' => '📤', 'group' => 'Accounting & Keuangan'],
            'fin_vat_in'          => ['title' => 'e-Faktur Import VAT In', 'icon' => '📥', 'group' => 'Accounting & Keuangan'],
            'fin_extract_pdf'     => ['title' => 'e-Faktur Extract PDF', 'icon' => '📄', 'group' => 'Accounting & Keuangan'],

            // PENGATURAN & USER MANAGEMENT
            'app_settings'    => ['title' => 'Setting Aplikasi', 'icon' => '⚙️', 'group' => 'Administrator'],
            'user_management' => ['title' => 'Kelola User Perusahaan', 'icon' => '👥', 'group' => 'Administrator'],
            'system_tickets'  => ['title' => 'Pusat Tiket & Request', 'icon' => '🎫', 'group' => 'Sistem & Pengaturan']
        ];
    }

    /**
     * Preset Akses Menu & Izin Khusus Berdasarkan Role
     */
    function ceisa_get_role_preset($role) {
        $allMenus = array_keys(ceisa_get_all_menus());
        
        switch ($role) {
            case 'demo':
                $menus = [];
                foreach ($allMenus as $m) {
                    if (!in_array($m, ['user_management', 'app_settings'])) {
                        $menus[$m] = 'read';
                    }
                }
                return [
                    'menus' => $menus,
                    'special' => [
                        'can_post_it_inventory'      => false,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'purchase':
                return [
                    'menus' => [
                        'pur_req'            => 'write',
                        'pur_po'             => 'write',
                        'pur_pi'             => 'write',
                        'pur_pr'             => 'write',
                        'erp_buyer_supplier' => 'write',
                        'inv_material'       => 'read',
                        'erp_kurs'           => 'read',
                        'erp_term'           => 'read',
                        'erp_dept'           => 'read',
                        'erp_satuan'         => 'read',
                        'erp_tax_rate'       => 'read',
                        'it_inventory'       => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => false,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'sales':
                return [
                    'menus' => [
                        'sale_so'            => 'write',
                        'sale_si'            => 'write',
                        'sale_sr'            => 'write',
                        'sale_so_import'     => 'write',
                        'erp_buyer_supplier' => 'write',
                        'sale_do'            => 'read',
                        'inv_material'       => 'read',
                        'erp_kurs'           => 'read',
                        'erp_term'           => 'read',
                        'erp_dept'           => 'read',
                        'erp_satuan'         => 'read',
                        'erp_tax_rate'       => 'read',
                        'it_inventory'       => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => false,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'production':
                return [
                    'menus' => [
                        'scm_mr'             => 'write',
                        'scm_wo'             => 'write',
                        'scm_issue_bom'      => 'write',
                        'scm_issue_fgwip'    => 'write',
                        'scm_scrap'          => 'write',
                        'scm_disposal'       => 'write',
                        'scm_trace_bb'       => 'write',
                        'inv_stock_transfer' => 'write',
                        'inv_bom'            => 'read',
                        'inv_material'       => 'read',
                        'it_inventory'       => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => false,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'warehouse':
                return [
                    'menus' => [
                        'pur_grn'                => 'write',
                        'sale_do'                => 'write',
                        'scm_mr'                 => 'write',
                        'inv_qr_label'           => 'write',
                        'inv_warehouse_in'       => 'write',
                        'inv_warehouse_delivery' => 'write',
                        'inv_transfer_bc27'      => 'write',
                        'inv_scan27'             => 'write',
                        'inv_stok_opname'        => 'write',
                        'scm_rekap_so'           => 'write',
                        'inv_material'           => 'read',
                        'pur_po'                 => 'read',
                        'sale_so'                => 'read',
                        'it_inventory'           => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => true,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'exim':
                return [
                    'menus' => [
                        'daftar_dokumen'     => 'write',
                        'transfer'           => 'write',
                        'editor'             => 'write',
                        'import'             => 'write',
                        'dokap'              => 'write',
                        'tracking'           => 'write',
                        'manifest'           => 'write',
                        'inv_tarif'          => 'write',
                        'inv_jaminan'        => 'write',
                        'referensi'          => 'read',
                        'inv_material'       => 'read',
                        'erp_buyer_supplier' => 'read',
                        'it_inventory'       => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => false,
                        'can_transfer_ceisa'         => true,
                        'can_access_ceisa_dashboard' => true,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'accounting':
                return [
                    'menus' => [
                        'act_pay'             => 'write',
                        'act_rcv'             => 'write',
                        'fin_cash_bank'       => 'write',
                        'act_jurnal_beli'     => 'write',
                        'act_jurnal_jual'     => 'write',
                        'act_jurnal_umum'     => 'write',
                        'act_jurnal_kas_bank' => 'write',
                        'act_jurnal_mutasi'   => 'write',
                        'fin_gl'              => 'write',
                        'act_kodebantu'       => 'write',
                        'act_konfig'          => 'write',
                        'act_fa'              => 'write',
                        'fin_ap_ar'           => 'write',
                        'act_lap_keu'         => 'write',
                        'act_lap_multi'       => 'write',
                        'fin_faktur'          => 'write',
                        'fin_vat_out'         => 'write',
                        'fin_vat_in'          => 'write',
                        'fin_extract_pdf'     => 'write',
                        'pur_po'              => 'read',
                        'pur_pi'              => 'read',
                        'sale_so'             => 'read',
                        'sale_si'             => 'read',
                        'inv_material'        => 'read',
                        'erp_kurs'            => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => false,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'admin_inventory':
                return [
                    'menus' => [
                        'inv_material'           => 'write',
                        'inv_koreksi_klas'       => 'write',
                        'inv_bom'                => 'write',
                        'scm_mr'                 => 'write',
                        'inv_qr_label'           => 'write',
                        'inv_tarif'              => 'write',
                        'inv_jaminan'            => 'write',
                        'inv_stok_awal'          => 'write',
                        'inv_stok_opname'        => 'write',
                        'inv_unposting_appv'     => 'write',
                        'inv_adjust_spp'         => 'write',
                        'inv_stock_transfer'     => 'write',
                        'it_inventory'           => 'write',
                        'inv_warehouse_in'       => 'read',
                        'inv_warehouse_delivery' => 'read',
                        'pur_po'                 => 'read',
                        'sale_do'                => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => true,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => true
                    ]
                ];

            case 'bc':
                return [
                    'menus' => [
                        'it_inventory'   => 'read',
                        'daftar_dokumen' => 'read',
                        'version_log'    => 'read',
                        'system_tickets' => 'read',
                        'inv_qr_label'   => 'read'
                    ],
                    'special' => [
                        'can_post_it_inventory'      => false,
                        'can_transfer_ceisa'         => false,
                        'can_access_ceisa_dashboard' => false,
                        'can_unposting_approval'     => false
                    ]
                ];

            case 'admin':
            case 'superadmin':
            default:
                $menus = [];
                foreach ($allMenus as $m) {
                    $menus[$m] = 'write';
                }
                return [
                    'menus' => $menus,
                    'special' => [
                        'can_post_it_inventory'      => true,
                        'can_transfer_ceisa'         => true,
                        'can_access_ceisa_dashboard' => true,
                        'can_unposting_approval'     => true
                    ]
                ];
        }
    }

    /**
     * Mem-parsing JSON allowed_menus dari database menjadi struktur terstandarisasi
     */
    function ceisa_parse_user_permissions($rawJson) {
        $default = [
            'is_all'  => false,
            'menus'   => [],
            'special' => [
                'can_post_it_inventory'      => false,
                'can_transfer_ceisa'         => false,
                'can_access_ceisa_dashboard' => false,
                'can_unposting_approval'     => false
            ]
        ];

        if ($rawJson === '*' || $rawJson === 'ALL' || empty($rawJson)) {
            $default['is_all'] = true;
            $allMenus = array_keys(ceisa_get_all_menus());
            foreach ($allMenus as $m) {
                $default['menus'][$m] = 'write';
            }
            $default['special']['can_post_it_inventory']      = true;
            $default['special']['can_transfer_ceisa']         = true;
            $default['special']['can_access_ceisa_dashboard'] = true;
            $default['special']['can_unposting_approval']     = true;
            return $default;
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            $default['menus'][$rawJson] = 'write';
            return $default;
        }

        if (isset($decoded['menus']) && is_array($decoded['menus'])) {
            $default['menus'] = $decoded['menus'];
            if (isset($decoded['special']) && is_array($decoded['special'])) {
                foreach ($decoded['special'] as $k => $v) {
                    $default['special'][$k] = !empty($v);
                }
            }
            return $default;
        }

        if (is_array($decoded)) {
            if (in_array('*', $decoded) || in_array('ALL', $decoded)) {
                $default['is_all'] = true;
                $allMenus = array_keys(ceisa_get_all_menus());
                foreach ($allMenus as $m) {
                    $default['menus'][$m] = 'write';
                }
                $default['special']['can_post_it_inventory']      = true;
                $default['special']['can_transfer_ceisa']         = true;
                $default['special']['can_access_ceisa_dashboard'] = true;
                $default['special']['can_unposting_approval']     = true;
                return $default;
            }

            foreach ($decoded as $val) {
                if (is_string($val) && !empty($val)) {
                    $default['menus'][$val] = 'write';
                }
            }
        }

        return $default;
    }

    /**
     * Memeriksa apakah menu diizinkan dibuka
     */
    function ceisa_can_access($menuKey) {
        if ($menuKey === 'system_tickets' || $menuKey === 'ticket' || $menuKey === 'version_log') {
            return true;
        }
        if (!empty($_SESSION['is_super_admin']) || !empty($_SESSION['is_admin'])) {
            return true;
        }
        $role = $_SESSION['user_role'] ?? 'operator';
        if ($role === 'superadmin' || $role === 'admin') {
            return true;
        }
        if ($role === 'bc') {
            return ($menuKey === 'it_inventory' || $menuKey === 'daftar_dokumen');
        }

        $authorities = $_SESSION['menu_authorities'] ?? [];
        if (isset($authorities['*']) && $authorities['*'] === 'write') {
            return true;
        }
        return !empty($authorities[$menuKey]);
    }

    /**
     * Memeriksa apakah menu berwenang melakukan SAVE / SIMPAN / EDIT / HAPUS (Full Access)
     */
    function ceisa_can_save($menuKey) {
        if (!empty($_SESSION['is_super_admin']) || !empty($_SESSION['is_admin'])) {
            return true;
        }
        $role = $_SESSION['user_role'] ?? 'operator';
        if ($role === 'demo' || $role === 'bc') {
            return false;
        }
        if ($role === 'superadmin' || $role === 'admin') {
            return true;
        }

        $authorities = $_SESSION['menu_authorities'] ?? [];
        if (isset($authorities['*']) && $authorities['*'] === 'write') {
            return true;
        }
        $mode = $authorities[$menuKey] ?? '';
        return ($mode === 'write');
    }

    /**
     * Memeriksa izin operasional khusus (Special Permission)
     */
    function ceisa_has_permission($permKey) {
        if (!empty($_SESSION['is_super_admin']) || !empty($_SESSION['is_admin'])) {
            return true;
        }
        $role = $_SESSION['user_role'] ?? 'operator';
        if ($role === 'superadmin' || $role === 'admin') {
            return true;
        }
        if ($role === 'demo' || $role === 'bc') {
            return false;
        }

        $special = $_SESSION['special_permissions'] ?? [];
        return !empty($special[$permKey]);
    }

    /**
     * Render Banner Mode Baca Saja (Read Only)
     */
    function ceisa_render_readonly_badge($title = '') {
        return '<div class="ant-alert-readonly" style="display:inline-flex; align-items:center; gap:6px; background:rgba(250,173,20,0.12); border:1px solid rgba(250,173,20,0.35); color:#d46b08; padding:3px 10px; border-radius:4px; font-size:10px; font-weight:600; margin-left:8px;">
            <i class="fa-solid fa-lock" style="font-size:10px;"></i>
            <span>Mode Baca Saja (Read Only)' . (!empty($title) ? ' - ' . htmlspecialchars($title) : '') . '</span>
        </div>';
    }
}
