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
				',"flagVd":"', coalesce(flag_vd, 'T') , '"',
				',"fob":', round(coalesce(fob, '0'), 2) , 
				',"freight":', round(coalesce(freight, '0'), 2) , 				
				',"hargaPenyerahan":', round(coalesce(harga_penyerahan, '0'), 2) , 
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"jumlahKontainer":',  coalesce((SELECT count(1) FROM bc_ceisa_kontainer WHERE nomor_aju = h.nomor_aju), 0) , 
				',"kodeAsuransi":"',  coalesce(kode_asuransi, 'LN')   , '"',
				',"kodeCaraBayar":"', coalesce(kode_cara_bayar, '1') , '"',
			 	',"kodeDokumen":"',  '20' , '"',
			   	',"kodeIncoterm":"',  coalesce(kode_incoterm, '')   , '"',
				',"kodeJenisImpor":"', coalesce(kode_jenis_impor, '1') , '"',
				',"kodeJenisNilai":"', coalesce(kode_jenis_nilai, '') , '"',
				',"kodeJenisProsedur":"', coalesce(kode_jenis_prosedur, '1') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeKantorBongkar":"', coalesce(kode_kantor_bongkar, kode_kantor, '') , '"',
				',"kodePelBongkar":"', coalesce(kode_pelabuhan_bongkar, '') , '"',
				',"kodePelMuat":"', coalesce(kode_pelabuhan_muat, '') , '"',
				',"kodePelTransit":"', coalesce(kode_pelabuhan_transit, '') , '"',
				',"kodePelTujuan":"', coalesce(kode_pelabuhan_tujuan, kode_pelabuhan_bongkar, '') , '"',
				',"kodeTps":"', coalesce(kode_tps, '') , '"',
				',"kodeTutupPu":"', coalesce(kode_tutup_pu, '11') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, '') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"', 
				',"ndpbm":', round(coalesce(ndpbm, '0'), 4) , 
				',"netto":', round(coalesce(netto, '0'), 4) , 
				',"nik":"', ' ' , '"',
				',"nilaiBarang":', round(coalesce(nilai_barang, '0') , 2),
			    ',"nomorAju":"', coalesce(nomor_aju, '') , '"',
				',"nomorBc11":"', coalesce(nomor_bc11, '') , '"',
				',"posBc11":"', coalesce(nomor_pos, '') , '"',
				',"seri":',  '1'  ,  		 	
				',"subposBc11":"',  coalesce(nomor_sub_pos, '') , '"', 
				',"tanggalBc11":"', coalesce(tanggal_bc11, tanggal_pernyataan) , '"',
				',"tanggalTiba":"', coalesce(tanggal_tiba, '') , '"',	 	
				',"tanggalTtd":"', coalesce(tanggal_pernyataan, '')  , '"',
				',"biayaTambahan":', round(coalesce(biaya_tambahan, '0'), 2) , 
			 	',"biayaPengurang":', round(coalesce(biaya_pengurang, '0'), 2) , 
			 	',"totalDanaSawit":', round(coalesce(total_dana_sawit, '0'), 2) , 
			 	',"vd":', round(coalesce(vd, '0'), 2) , 
			 	',"uangMuka":', round(coalesce(uang_muka, '0'), 2) , 
			 	',"volume":', round(coalesce(volume, '0'), 4)
	           )
	     ) as json
	FROM bc_ceisa_header h WHERE nomor_aju = :ls_aju
	 
	UNION ALL
    
	SELECT 'entitas' tabel, coalesce(
	    CONCAT(
	       '"entitas": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"alamatEntitas":"',  coalesce(alamat_entitas, '') , '"',
				  	',"kodeEntitas":"', coalesce(a.kode_entitas,'') , '"', 
				 	',"kodeJenisApi":"', coalesce(kode_jenis_api,'') , '"',
				  	',"kodeJenisIdentitas":"', coalesce(kode_jenis_identitas,'') , '"',  
				 	',"nomorIjinEntitas":"', coalesce(nomor_ijin_entitas,'') , '"',  
					',"namaEntitas":"', coalesce(nama_entitas,'') , '"', 
					',"nibEntitas":"', coalesce(nib_entitas,'') , '"', 
					',"kodeStatus":"', coalesce(kode_status,'') , '"', 
					',"nomorIdentitas":"',  coalesce(nomor_identitas,'') , '"',  
				    ',"seriEntitas":', coalesce(seri, '0') ,  
					',"tanggalIjinEntitas":"', coalesce(tanggal_ijin_entitas,'') , '"', 
				 	',"kodeNegara":"', coalesce(kode_negara,'') , '"',  
				 	',"kodeAfiliasi":"', coalesce(nullif(kode_afiliasi, ''), 'TAH') , '"}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ), '"entitas": []') as json
	 	FROM bc_ceisa_entitas a
	 	LEFT JOIN bc_ceisa_entidx b ON b.bc='20' AND a.kode_entitas = b.kode_entitas
	 	WHERE a.nomor_aju = :ls_aju
	
	UNION ALL
   	
	SELECT 'dokumen' tabel, coalesce(
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"',  coalesce(kode_dokumen, '-')  , '"',
	               ',"nomorDokumen":"', coalesce(nomor_dokumen, '-') , '"',
	               ',"seriDokumen":', coalesce(seri, '0') , 
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
	               '{"namaPengangkut":"',  coalesce(nama_pengangkut, '-')  , '"',
	               ',"nomorPengangkut":"', coalesce(nomor_pengangkut,'-') , '"',
	               ',"kodeBendera":"', coalesce(kode_bendera, '' ) , '"',
	               ',"kodeCaraAngkut":"', coalesce(kode_cara_angkut, '' ) , '"',
	               ',"seriPengangkut":',  coalesce(seri, '1')  , '}'
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
	               '{"kodeJenisKontainer":"',  coalesce(kode_jenis_kontainer, '') , '"',
	               ',"kodeTipeKontainer":"', coalesce(kode_tipe_kontainer, '') , '"',
	               ',"kodeUkuranKontainer":"', coalesce(kode_ukuran_kontainer, '') , '"',
	               ',"nomorKontainer":"', coalesce(nomor_kontainer, '-') , '"',
	               ',"seriKontainer":',  coalesce(seri, 1)  , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ) , '"kontainer": []') as json
	FROM bc_ceisa_kontainer WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'kemasan' tabel, coalesce(
	    CONCAT(
	       '"kemasan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"jumlahKemasan":',  coalesce(round(jumlah_kemasan,0), 0)  ,  
	               ',"kodeJenisKemasan":"', coalesce(kode_kemasan, '-') , '"',
	               ',"merkKemasan":"', coalesce(merek, '') , '"',
	               ',"seriKemasan":',  coalesce(seri, 1)  , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    )  , '"kemasan": []') as json
	FROM bc_ceisa_kemasan WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'pungutan' tabel, coalesce(
	    CONCAT(
	       '"pungutan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeFasilitasTarif":"', coalesce(kode_fasilitas_tarif, '') , '"',
	               ',"kodeJenisPungutan":"', coalesce(kode_jenis_pungutan, '') , '"',
	               ',"nilaiPungutan":',  coalesce(nilai_pungutan, 0)  , '}'
	           )
	       ),
	       ']'
	    )  , '"pungutan": []') as json
	FROM bc_ceisa_pungutan WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'bankDevisa' tabel, coalesce(
	    CONCAT(
	       '"bankDevisa": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeBank":"',  coalesce(kode, '')  , '"',
	               ',"namaBank":"', coalesce(nama, '') , '"',
	               ',"seriBank":',  coalesce(seri, 0), '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ) , '"bankDevisa": []') as json
	FROM bc_ceisa_bankdevisa WHERE nomor_aju = :ls_aju
	
 	UNION ALL
	
	SELECT 'barang' tabel,
	    CONCAT( '"barang": [', GROUP_CONCAT( CONCAT(
	               '{"asuransi":',  round(coalesce(x.asuransi, 0.00), 2)   ,  
	               ',"bruto":',   round(coalesce(x.bruto, 0.00), 4)   ,  
	               ',"cif":',   round(coalesce(x.cif, 0.00), 2)  , 
				 ',"fob":',   round(coalesce(x.fob, 0.00), 2)   , 
	               ',"freight":',   round(coalesce(x.freight, 0.00), 2)   , 		
	               ',"diskon":',   round(coalesce(x.diskon, 0.00), 2)   , 
				  ',"kodeNegaraAsal":"',   coalesce(kode_negara_asal, ' ')   , '"',
				 ',"kodeKategoriBarang":"', coalesce(kode_kategori_barang, '')  , '"',				
	               ',"hargaEkspor":', round(coalesce(harga_ekspor, '0'), 2)   , 
			     ',"isiPerKemasan":',   coalesce(round(isi_per_kemasan, 0), 0)      ,  
	               ',"hargaPenyerahan":',   round(coalesce(x.harga_penyerahan, 0.00), 2)   ,  
	               ',"hargaSatuan":',   round(coalesce(harga_satuan, 0.00), 4)   , 
	               ',"jumlahKemasan":',   round(coalesce(x.jumlah_kemasan, 0.00), 2)  , 
	               ',"jumlahRealisasi":',  round(coalesce(x.jumlah_realisasi, 0.00), 4)  ,   
	               ',"jumlahSatuan":',   round(coalesce(jumlah_satuan, 0.00), 4)   ,  
	               ',"kodeBarang":"',   coalesce(kode_barang, '')   , '"',
	               ',"kodeJenisKemasan":"',   coalesce(kode_kemasan, '-')   , '"',
	               ',"kodeKondisiBarang":"',   coalesce(kode_kondisi_barang, '1')   , '"',
	               ',"kodeSatuanBarang":"',   coalesce(kode_satuan, '-')   , '"',
	               ',"merk":"',   CASE WHEN length(coalesce(merek, '')) < 2 THEN '--' ELSE merek END   , '"',
	               ',"netto":',   round(coalesce(x.netto, 0.00), 4)   , 
	               ',"nilaiBarang":',   round(coalesce(nilai_barang, 0.00), 2)  ,  
	               ',"posTarif":"',   coalesce(hs, '')   , '"',
	               ',"seriBarang":',   coalesce(seri_barang, 0)   ,  
	               ',"spesifikasiLain":"',   coalesce(spesifikasi_lain, '')   , '"',
	               ',"tipe":"',   CASE WHEN length(coalesce(tipe, '')) < 2 THEN '--' ELSE tipe END   , '"',
	               ',"ukuran":"',   coalesce(ukuran, '-')   , '"',
	               ',"uraian":"',   coalesce(uraian, '-')   , '"',
	               ',"volume":',   round(coalesce(x.volume, 0.00), 4)   ,  
	               ',"cifRupiah":',   round(coalesce(x.cif_rupiah, 0.00), 2)   , 
	               ',"hargaPerolehan":',   round(coalesce(x.harga_perolehan, 0.00), 2)   ,  
	               ',"kodeAsalBahanBaku":"',  coalesce(kode_asal_barang, '0') , '"', 
	               ',"ndpbm":',   round(coalesce(ndpbm, 0.00), 4)   , 
	               ',"pernyataanLartas":"', coalesce(pernyataan_lartas, 'T') , '"',
	               ',"saldoAwal":',   round(coalesce(saldo_awal, 0.00), 4)   , 
	               ',"saldoAkhir":',   round(coalesce(saldo_akhir, 0.00), 4)   , 
	               ',"metodePenentuanNilai":"', coalesce(nullif(metode_penentuan_nilai, ''), 'Metode 1') , '"',
	               ',"alasanMetodePenentuanNilai":', CASE WHEN metode_penentuan_nilai IS NOT NULL AND metode_penentuan_nilai != 'Metode 1' AND alasan_metode_penentuan_nilai IS NOT NULL THEN concat('"', alasan_metode_penentuan_nilai, '"') ELSE 'null' END ,
	               ',"statementPerbedaanHarga":"', coalesce(nullif(statement_perbedaan_harga, ''), 'T') , '"',
	               ',"nilaiJasa":',   round(coalesce(nilai_jasa, '0'), 2)   , 
			     ',"nilaiTambah":',   round(coalesce(nilai_tambah, '0'), 2)   , 		
				 ',"barangDokumen":',
				 coalesce((SELECT CONCAT('[', GROUP_CONCAT(CONCAT(
				     '{"seriDokumen":', coalesce(seri_dokumen, '1'), '}'
				 ) ORDER BY seri_dokumen), ']')
				 FROM bc_ceisa_barangdokumen bd WHERE bd.nomor_aju = x.nomor_aju AND bd.seri_barang = x.seri_barang), '[]'),
				 ',"barangVd":',
				 coalesce((SELECT CONCAT('[', GROUP_CONCAT(CONCAT(
				     '{"kodeJenisVd":"', coalesce(kode_vd, ''), '",',
				     '"nilaiBarangVd":', round(coalesce(nilai_barang, 0), 4), '}'
				 )), ']')
				 FROM bc_ceisa_barangvd bv WHERE bv.nomor_aju = x.nomor_aju AND bv.seri_barang = x.seri_barang), '[]'),
	               ',"barangTarif":',                  
			               coalesce((SELECT  CONCAT( '[',  GROUP_CONCAT( CONCAT(
			               '{"kodeJenisTarif":"',  coalesce(kode_tarif, '1')   , '"',
							',"jumlahSatuan":',   round(coalesce(jumlah_satuan, 0.00), 4)   , 
							',"kodeFasilitasTarif":"',   coalesce(kode_fasilitas, 0)     , '"',
							',"kodeSatuanBarang":"',   coalesce(x.KODE_SATUAN, '-')   , '"',
							',"nilaiBayar":',   round(coalesce(nilai_bayar, 0.00), 2)   , 
							',"nilaiFasilitas":',   round(coalesce(nilai_fasilitas, 0.00), 2)   , 
							',"nilaiSudahDilunasi":',   round(coalesce(nilai_sudah_dilunasi, 0.00), 2)   , 
							',"seriBarang":',   coalesce(seri_barang, '0')   , 
							',"tarif":',   round(coalesce(tarif, '0'), 4)   ,  
							',"tarifFasilitas":',   round(coalesce(tarif_fasilitas, '0'), 4)   , 
							',"kodeJenisPungutan":"',   coalesce(kode_pungutan, '-')    , '"}'
							) ORDER BY kode_pungutan ), ']' )  
							FROM bc_ceisa_barangtarif y WHERE x.nomor_aju = y.nomor_aju AND x.seri_barang= y.seri_barang ) ,'[]'), '}')), ']' ) as json
	FROM bc_ceisa_barang x WHERE nomor_aju = :ls_aju
) dt
