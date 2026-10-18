SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"',  'S'  , '"',
				',"asuransi":',  round(coalesce(asuransi, '0'), 2)  , 
				',"bruto":', round(coalesce(bruto, '0'), 4) ,  
				',"cif":', round(coalesce(cif, '0'), 2) , 
				',"disclaimer":"1"',
				',"flagCurah":"', coalesce(flag_curah, '2') , '"',
				',"freight":', round(coalesce(freight, '0'), 2) , 
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"jumlahKontainer":', coalesce((SELECT count(1) FROM bc_ceisa_kontainer WHERE nomor_aju = h.nomor_aju), 0) , 
				',"kodeAsuransi":"', coalesce(kode_asuransi, 'LN') , '"',
				',"kodeCaraAngkutPlb":"', coalesce(kode_jenis_pengangkutan, '1') , '"',
				',"kodeCaraBayar":"', coalesce(kode_cara_bayar, '1') , '"',	
				',"kodeCaraDagang":"', coalesce(kode_cara_dagang, '1') , '"',	
				',"kodeDaerahAsal":"', coalesce(kode_daerah_asal, '') , '"',	
				',"kodeDokumen":"', '33' , '"',
				',"kodeGudangAsal":"', coalesce(kode_gudang_asal, '') , '"',
				',"kodeIncoterm":"', coalesce(kode_incoterm, '') , '"',
				',"kodeJenisEkspor":"', coalesce(kode_jenis_ekspor, '1') , '"',
				',"kodeJenisProsedur":"', coalesce(kode_jenis_prosedur, '1') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeKategoriEkspor":"', coalesce(kode_kategori_ekspor, '1') , '"',
				',"kodeNegaraTujuan":"', coalesce(kode_negara_tujuan, '') , '"',
				',"kodePelBongkar":"', coalesce(kode_pelabuhan_bongkar, kode_pelabuhan_tujuan, '') , '"',
				',"kodePelMuat":"', coalesce(kode_pelabuhan_muat, '') , '"',
				',"kodePelTujuan":"', coalesce(kode_pelabuhan_tujuan, '') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, 'USD') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"', 
				',"ndpbm":', round(coalesce(ndpbm, '0'), 4) , 
				',"netto":', round(coalesce(netto, '0'), 4) , 
				',"nilaiBarang":', round(coalesce(nilai_barang, '0'), 2) , 
				',"nilaiMaklon":', round(coalesce(nilai_maklon, '0'), 2) , 
				',"nomorAju":"', coalesce(nomor_aju, '') , '"',				
				',"seri":', '1' ,  		 					
				',"tanggalAju":"', coalesce(tanggal_pernyataan, '') , '"',	 	 
				',"tanggalMasuk":"', coalesce(tanggal_masuk, tanggal_pernyataan, '') , '"',
				',"tanggalMuat":"', coalesce(tanggal_muat, tanggal_pernyataan, '') , '"',
				',"tanggalTtd":"', coalesce(tanggal_pernyataan, '') , '"'
	           )
	     ) as json
	FROM bc_ceisa_header h WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'barang' tabel,
	    CONCAT(
	       '"barang": [',
	       GROUP_CONCAT(
	           CONCAT(
				'{"cif":', round(coalesce(cif, '0'), 2) , 
				',"fob":', round(coalesce(fob, '0'), 2) , 
				',"hargaEkspor":', round(coalesce(harga_ekspor, '0'), 4) , 
				',"hargaSatuan":', round(coalesce(harga_satuan, '0'), 2) , 
				',"jumlahKemasan":', round(coalesce(jumlah_kemasan, '0'), 2) , 
				',"jumlahSatuan":', round(coalesce(jumlah_satuan, '0'), 4) , 
				',"nilaiBarang":', round(coalesce(nilai_barang, '0'), 2) , 
				',"kodeBarang":"', coalesce(kode_barang, '') , '"', 
				',"kodeDokumen":"', '33', '"', 
				',"kodeJenisKemasan":"', coalesce(kode_kemasan, '--') , '"', 
				',"kodeNegaraAsal":"', coalesce(kode_negara_asal, '') , '"', 
				',"kodeSatuanBarang":"', coalesce(kode_satuan, '') , '"', 
				',"merk":"', case when coalesce(merek, '') in ('', '-') then '--' else merek end , '"', 
				',"ndpbm":', round(coalesce(ndpbm, '0'), 4) , 
				',"netto":', round(coalesce(netto, '0'), 4) , 
				',"volume":', round(coalesce(volume, '0'), 4) , 
				',"seriBarang":', coalesce(seri_barang, 1) ,  
				',"posTarif":"', coalesce(hs, '') , '"', 
				',"spesifikasiLain":"', coalesce(spesifikasi_lain, '-') , '"', 
				',"tipe":"', case when coalesce(tipe, '') in ('', '-') then '--' else tipe end , '"', 
				',"ukuran":"', coalesce(ukuran, '-') , '"', 
				',"uraian":"', coalesce(uraian, '-') , '"', 
				',"barangDokumen": []',   
				',"barangPemilik": [ { "seriEntitas": 2 } ]',   
				',"barangTarif": []}'
	           ) ORDER BY seri_barang 
	       ),
	       ']'
	    ) as json
	FROM bc_ceisa_barang WHERE nomor_aju = :ls_aju	
	
	UNION ALL
    
	SELECT 'entitas' tabel,
	    CONCAT(
	       '"entitas": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"alamatEntitas":"', coalesce(a.alamat_entitas, '-') , '"',
				  	',"kodeEntitas":"', coalesce(a.kode_entitas, '') , '"', 
				  	',"kodeJenisIdentitas":"', coalesce(a.kode_jenis_identitas, '5') , '"',  
				  	',"kodeStatus":"', coalesce(a.kode_status, '') , '"',  
					',"namaEntitas":"', coalesce(a.nama_entitas, '-') , '"', 
					',"nibEntitas":"', coalesce(a.nib_entitas, '') , '"', 
					',"nomorIdentitas":"', coalesce(a.nomor_identitas, '-') , '"',  
					',"nomorIjinEntitas":"', coalesce(a.nomor_ijin_entitas, '') , '"',  
				    ',"seriEntitas":', coalesce(a.seri, 1) ,  
				    ',"tanggalIjinEntitas":"', coalesce(date_format(a.tanggal_ijin_entitas, '%Y-%m-%d'), '') , '"',  
				 	',"kodeNegara":"', coalesce(a.kode_negara, 'ID') , '"}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ) as json
 	FROM bc_ceisa_entitas a
 	LEFT JOIN bc_ceisa_entidx b ON b.bc = '33' AND a.kode_entitas = b.kode_entitas 
 	WHERE a.nomor_aju = :ls_aju
	
	UNION ALL
   	
	SELECT 'dokumen' tabel, coalesce(
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"', coalesce(kode_dokumen, '999') , '"',
	               ',"nomorDokumen":"', coalesce(nomor_dokumen, '-') , '"',
	               ',"seriDokumen":', coalesce(seri, 1) , 
	               ',"tanggalDokumen":"', date_format(tanggal_dokumen, '%Y-%m-%d'), '"}'
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
	               '{"namaPengangkut":"', coalesce(nama_pengangkut, '-') , '"',
	               ',"nomorPengangkut":"', coalesce(nomor_pengangkut, '-') , '"',
	               ',"kodeBendera":"', coalesce(kode_bendera, 'ID') , '"',
	               ',"kodeCaraAngkut":"', coalesce(kode_cara_angkut, '1') , '"',
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
	
	SELECT 'bankDevisa' tabel, coalesce(
	    CONCAT(
	       '"bankDevisa": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeBank":"', coalesce(kode, '') , '"',
	               ',"namaBank":"', coalesce(nama, '-') , '"',
	               ',"seriBank":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"bankDevisa": []') as json
	FROM bc_ceisa_bankdevisa WHERE nomor_aju = :ls_aju

) dt
