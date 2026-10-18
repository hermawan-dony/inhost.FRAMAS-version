SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"', 'S' , '"',
				',"biayaPengurang":', round(coalesce(biaya_pengurang, 0), 4) , 
				',"biayaTambahan":', round(coalesce(biaya_tambahan, 0), 4) , 
				',"bruto":', round(coalesce(bruto, 0), 4) ,  
				',"cif":', round(coalesce(cif, 0), 2) , 
				',"disclaimer":"1"',
				',"freight":', round(coalesce(freight, 0), 2) , 
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"kodeCaraBayar":"', coalesce(kode_cara_bayar, '1') , '"',
				',"kodeDokumen":"28"',
				',"kodeGudangAsal":"', coalesce(kode_gudang_asal, '') , '"',
				',"kodeIncoterm":"', coalesce(kode_incoterm, 'CIF') , '"',
				',"kodeJenisImpor":"', coalesce(kode_jenis_impor, '1') , '"',
				',"kodeJenisNilai":"', coalesce(kode_jenis_nilai, '1') , '"',
				',"kodeJenisProsedur":"', coalesce(kode_jenis_prosedur, '1') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, 'USD') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"', 
				',"ndpbm":', round(coalesce(ndpbm, 0), 4) , 
				',"netto":', round(coalesce(netto, 0), 4) , 
				',"nik":" "',
				',"nilaiIncoterm":', round(coalesce(nilai_incoterm, cif, 0), 2) , 
			    ',"nomorAju":"', coalesce(nomor_aju, '') , '"',
				',"seri":', 1 ,  		 	
				',"tanggalAju":"', coalesce(date_format(tanggal_pernyataan, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
				',"tanggalTtd":"', coalesce(date_format(tanggal_pernyataan, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
				',"vd":', round(coalesce(vd, 0), 4)
	           )
	     ) as json
	FROM bc_ceisa_header h WHERE nomor_aju = :ls_aju
	 
	UNION ALL
    
	SELECT 'entitas' tabel, coalesce(
	    CONCAT(
	       '"entitas": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"alamatEntitas":"', coalesce(a.alamat_entitas, '-') , '"',
				  	',"kodeEntitas":"', coalesce(a.kode_entitas, '') , '"', 
				  	case when a.kode_entitas in ('1', '3') then concat(',"kodeJenisApi":"', coalesce(a.kode_jenis_api, '01'), '"') else '' end,
				  	case when a.kode_entitas in ('1', '3', '7', '4') then concat(',"kodeJenisIdentitas":"', coalesce(a.kode_jenis_identitas, '5'), '"') else '' end,
				 	case when a.kode_entitas in ('10') then concat(',"kodeNegara":"', coalesce(a.kode_negara, 'US'), '"') else '' end,
				 	case when a.kode_entitas in ('1', '3') then concat(',"kodeStatus":"', coalesce(a.kode_status, '1'), '"') else '' end,
					',"namaEntitas":"', coalesce(a.nama_entitas, '-') , '"', 
					case when a.kode_entitas in ('1', '3') then concat(',"nibEntitas":"', coalesce(a.nib_entitas, ''), '"') else '' end,
					case when a.kode_entitas in ('1', '3', '7', '4') then concat(',"nomorIdentitas":"', coalesce(a.nomor_identitas, '-'), '"') else '' end,
				 	case when a.kode_entitas = '3' then concat(',"nomorIjinEntitas":"', coalesce(a.nomor_ijin_entitas, ''), '"') else '' end,
				    ',"seriEntitas":', coalesce(a.seri, 1) ,  
					case when a.kode_entitas = '3' and a.tanggal_ijin_entitas is not null and a.tanggal_ijin_entitas != '' then concat(',"tanggalIjinEntitas":"', date_format(a.tanggal_ijin_entitas, '%Y-%m-%d'), '"') else '' end,
					'}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ), '"entitas": []') as json
	FROM bc_ceisa_entitas a
	LEFT JOIN bc_ceisa_entidx b on b.bc = '28' and b.kode_entitas = a.kode_entitas
	WHERE nomor_aju = :ls_aju 
	
	UNION ALL
	 
	SELECT 'dokumen' tabel, coalesce(
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"', coalesce(kode_dokumen, '380') , '"',
				   ',"kodeFasilitas":"', coalesce(kode_fasilitas, '') , '"',
	               ',"nomorDokumen":"', coalesce(nomor_dokumen, '-') , '"',
	               ',"seriDokumen":', coalesce(seri, 1) , 
	               ',"tanggalDokumen":"', coalesce(date_format(tanggal_dokumen, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')), '"}'
	           ) ORDER BY seri 
	       ),
	       ']'
	    ), '"dokumen": []') as json
	FROM bc_ceisa_dokumen WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'pengangkut' tabel, coalesce(
	    CONCAT(
	       '"pengangkut": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeCaraAngkut":"', coalesce(kode_cara_angkut, '1') , '"',
	               ',"seriPengangkut":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"pengangkut": []') as json
	FROM bc_ceisa_pengangkut WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'kontainer' tabel, coalesce(
	    CONCAT(
	       '"kontainer": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeJenisKontainer":"', coalesce(kode_jenis_kontainer, '4') , '"',
	               ',"kodeTipeKontainer":"', coalesce(kode_tipe_kontainer, '1') , '"',
	               ',"kodeUkuranKontainer":"', coalesce(kode_ukuran_kontainer, '20') , '"',
	               ',"nomorKontainer":"', coalesce(nomor_kontainer, '-') , '"',
	               ',"seriKontainer":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"kontainer": []') as json
	FROM bc_ceisa_kontainer WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'kemasan' tabel, coalesce(
	    CONCAT(
	       '"kemasan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"jumlahKemasan":', coalesce(round(jumlah_kemasan, 0), 1) ,  
	               ',"kodeJenisKemasan":"', coalesce(kode_kemasan, 'PK') , '"',
	               ',"merkKemasan":"', coalesce(merek, '-') , '"',
	               ',"seriKemasan":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"kemasan": []') as json
	FROM bc_ceisa_kemasan WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'barang' tabel, coalesce(
	    CONCAT(
	       '"barang": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"cif":', round(coalesce(x.cif, 0), 2) , 
	               ',"cifRupiah":', round(coalesce(x.cif_rupiah, 0), 2) ,  
	               ',"hargaPerolehan":', round(coalesce(x.harga_perolehan, 0), 2) ,  
	               ',"jumlahKemasan":', round(coalesce(x.jumlah_kemasan, 0), 2) , 
	               ',"jumlahSatuan":', round(coalesce(x.jumlah_satuan, 0), 4) , 
	               ',"kodeAsalBahanBaku":"', coalesce(x.kode_asal_barang, '0') , '"',
	               ',"kodeBarang":"', coalesce(x.kode_barang, '-') , '"',
	               ',"kodeDokAsal":"', coalesce(x.kode_dokumen_asal, '40') , '"',
	               ',"kodeJenisKemasan":"', coalesce(x.kode_kemasan, 'PK') , '"',
	               ',"kodeJenisNilai":"', coalesce(x.kode_jenis_nilai, '1') , '"',
	               ',"kodeKantorAsal":"', coalesce(x.kode_kantor_asal, '') , '"',
	               ',"kodeNegaraAsal":"', coalesce(x.kode_negara_asal, 'ID') , '"',
	               ',"kodePerhitungan":"', coalesce(x.kode_perhitungan, '0') , '"',
	               ',"kodeSatuanBarang":"', coalesce(x.kode_satuan, 'PCE') , '"',
	               ',"merk":"', case when coalesce(x.merek, '') in ('', '-') then '--' else x.merek end , '"',
	               ',"ndpbm":', round(coalesce(x.ndpbm, 0), 4) , 
	               ',"netto":', round(coalesce(x.netto, 0), 4) , 
	               ',"nilaiBarang":', round(coalesce(x.nilai_barang, 0), 2) , 
	               ',"nilaiTambah":', round(coalesce(x.nilai_tambah, 0), 2) , 
	               case when coalesce(x.nomor_aju_asal, '') != '' then concat(',"nomorAjuDokAsal":"', x.nomor_aju_asal, '"') else '' end,
	               case when coalesce(x.nomor_daftar_asal, '') != '' then concat(',"nomorDaftarDokAsal":"', x.nomor_daftar_asal, '"') else '' end,
	               ',"persentaseImpor":', round(coalesce(x.persentase_impor, 0), 2) , 
	               ',"posTarif":"', coalesce(x.hs, '') , '"',
	               ',"seriBarang":', coalesce(x.seri_barang, 1) , 
	               ',"seriBarangDokAsal":', coalesce(x.seri_barang_asal, 1) , 
	               ',"spesifikasiLain":"', coalesce(x.spesifikasi_lain, '-') , '"',
	               case when x.tanggal_daftar_asal is not null and x.tanggal_daftar_asal != '' then concat(',"tanggalDaftarDokAsal":"', date_format(x.tanggal_daftar_asal, '%Y-%m-%d'), '"') else '' end,
	               ',"tipe":"', case when coalesce(x.tipe, '') in ('', '-') then '--' else x.tipe end , '"',
	               ',"ukuran":"', coalesce(x.ukuran, '-') , '"',
	               ',"uraian":"', coalesce(x.uraian, '-') , '"',
	               ',"barangDokumen": []',
	               ',"barangTarif":', coalesce(
	                   ( SELECT CONCAT( '[', GROUP_CONCAT( CONCAT(
	                       '{"jumlahSatuan":', round(coalesce(t.jumlah_satuan, 0), 4) , 
	                       ',"kodeFasilitasTarif":"', coalesce(t.kode_fasilitas, '1') , '"',
	                       ',"kodeJenisPungutan":"', coalesce(t.kode_pungutan, 'BM') , '"',
	                       ',"kodeJenisTarif":"', coalesce(t.kode_tarif, '1') , '"',
	                       ',"kodeSatuanBarang":"', coalesce(t.kode_satuan, x.kode_satuan, 'PCE') , '"',
	                       ',"nilaiBayar":', round(coalesce(t.nilai_bayar, 0), 2) , 
	                       ',"nilaiFasilitas":', round(coalesce(t.nilai_fasilitas, 0), 2) , 
	                       ',"nilaiSudahDilunasi":', round(coalesce(t.nilai_sudah_dilunasi, 0), 2) , 
	                       ',"seriBarang":', coalesce(t.seri_barang, x.seri_barang, 1) , 
	                       ',"tarif":', round(coalesce(t.tarif, 0), 2) , 
	                       ',"tarifFasilitas":', round(coalesce(t.tarif_fasilitas, 0), 2) , '}'
	                   ) ORDER BY t.seri_barang ), ']' )
	                   FROM bc_ceisa_barangtarif t
	                   WHERE t.nomor_aju = x.nomor_aju AND t.seri_barang = x.seri_barang
	                   ), '[]' ) , 
	               '}'
	           ) ORDER BY x.seri_barang
	       ),
	       ']'
	    ), '"barang": []') as json
	FROM bc_ceisa_barang x WHERE nomor_aju = :ls_aju

) dt
