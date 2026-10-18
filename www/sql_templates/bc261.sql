SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"', 'S' , '"',
				',"asuransi":', round(coalesce(asuransi, '0'), 2) , 
				',"biayaPengurang":', round(coalesce(biaya_pengurang, '0'), 4) , 
				',"biayaTambahan":', round(coalesce(biaya_tambahan, '0'), 4) , 
				',"bruto":', round(coalesce(bruto, '0'), 4) ,  
				',"cif":', round(coalesce(cif, '0'), 2) , 
				',"disclaimer":"1"',
				',"freight":', round(coalesce(freight, '0'), 2) , 
				',"hargaPenyerahan":', round(coalesce(harga_penyerahan, '0'), 4) ,  
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"jumlahKontainer":', coalesce((SELECT count(1) FROM bc_ceisa_kontainer WHERE nomor_aju = h.nomor_aju), 0) ,  
				',"kodeDokumen":"261"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeTujuanPengiriman":"', coalesce(kode_tujuan_pengiriman, '1') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, 'IDR') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"',
				',"ndpbm":', round(coalesce(ndpbm, '0'), 4) , 
				',"netto":', round(coalesce(netto, '0'), 4) , 
				',"nik":" "',
				',"nilaiBarang":', round(coalesce(nilai_barang, '0'), 2) , 
				',"nomorAju":"', coalesce(nomor_aju, '') , '"',		
				',"seri":', 1 ,  
				',"tanggalAju":"', coalesce(tanggal_pernyataan, '') , '"',	 	 
				',"tanggalTtd":"', coalesce(tanggal_pernyataan, '') , '"',
				',"tempatStuffing":"', coalesce(tempat_stuffing, '-') , '"',
				',"tglAkhirBerlaku":"', coalesce(date_format(tanggal_pernyataan, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
				',"tglAwalBerlaku":"', coalesce(date_format(tanggal_pernyataan, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
				',"totalDanaSawit":', round(coalesce(total_dana_sawit, '0'), 2) , 
				',"uangMuka":', round(coalesce(uang_muka, '0'), 4) , 
				',"vd":', round(coalesce(vd, '0'), 4)
	           )
	     ) as json
	FROM bc_ceisa_header h WHERE nomor_aju = :ls_aju 
	
	UNION ALL	 
    
	SELECT 'entitas' tabel,
	    CONCAT(
	       '"entitas": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"alamatEntitas":"', coalesce(a.alamat_entitas, '-') , '"',
				  	',"kodeEntitas":"', coalesce(a.kode_entitas, '') , '"', 
				 	',"kodeJenisApi":"', coalesce(a.kode_jenis_api, '') , '"',
				    ',"kodeStatus":"', coalesce(a.kode_status, '') , '"',
				  	',"kodeJenisIdentitas":"', coalesce(a.kode_jenis_identitas, '5') , '"',  
				 	',"nomorIjinEntitas":"', coalesce(a.nomor_ijin_entitas, '') , '"',  
					',"namaEntitas":"', coalesce(a.nama_entitas, '-') , '"', 
					',"nibEntitas":"', coalesce(a.nib_entitas, '') , '"', 
					',"nomorIdentitas":"', coalesce(a.nomor_identitas, '-') , '"',  
				    ',"seriEntitas":', coalesce(a.seri, 1) ,  
					',"tanggalIjinEntitas":"', coalesce(date_format(a.tanggal_ijin_entitas, '%Y-%m-%d'), '') , '"}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ) as json
 	FROM bc_ceisa_entitas a
 	LEFT JOIN bc_ceisa_entidx b ON b.bc = '261' AND a.kode_entitas = b.kode_entitas 
 	WHERE a.nomor_aju = :ls_aju 
	
	UNION ALL
	
	SELECT 'dokumen' tabel, coalesce(
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"', coalesce(kode_dokumen, '999') , '"',
			       ',"kodeFasilitas":"', coalesce(kode_fasilitas, '') , '"',			
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
				   ',"idPengangkut":"1"',						
	               ',"nomorPengangkut":"', coalesce(nomor_pengangkut, '-') , '"',
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

	SELECT 'pungutan' tabel, coalesce(
	    CONCAT(
	       '"pungutan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeFasilitasTarif":"', coalesce(kode_fasilitas_tarif, '1') , '"',
				  ',"idPungutan":"1"',		
	               ',"kodeJenisPungutan":"', coalesce(kode_jenis_pungutan, 'PPN') , '"',
	               ',"nilaiPungutan":', round(coalesce(nilai_pungutan, 0), 2) , '}'
	           ) ORDER BY kode_jenis_pungutan
	       ),
	       ']'
	    ), '"pungutan": []') as json
	FROM bc_ceisa_pungutan WHERE nomor_aju = :ls_aju 
   
	UNION ALL
  	
	SELECT 'jaminan' tabel, coalesce(
	    CONCAT(
	       '"jaminan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"idJaminan":"1"',
	               ',"nomorBpj":"', coalesce(nomor_bpj, '-') , '"',
					',"tanggalBpj":"', coalesce(date_format(tanggal_bpj, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
					',"kodeJenisJaminan":"', coalesce(kode_jaminan, '1') , '"',
					',"nomorJaminan":"', coalesce(nomor_jaminan, '-') , '"',
					',"tanggalJaminan":"', coalesce(date_format(tanggal_jaminan, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
					',"tanggalJatuhTempo":"', coalesce(date_format(tanggal_jatuh_tempo, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
					',"penjamin":"', coalesce(penjamin, '-') , '"',	                    
					',"nilaiJaminan":', round(coalesce(nilai_jaminan, 0), 2) , '}'
	           ) ORDER BY nomor_jaminan
	       ),
	       ']'
	    ), '"jaminan": []') as json
	FROM bc_ceisa_jaminan WHERE nomor_aju = :ls_aju 
		
	UNION ALL

	SELECT 'barang' tabel,
	    CONCAT( '"barang": [', GROUP_CONCAT( CONCAT( 
	               '{"cif":', round(coalesce(x.cif, 0), 2) , 
	               ',"cifRupiah":', round(coalesce(x.cif_rupiah, 0), 2) , 
	               ',"hargaEkspor":', round(coalesce(x.harga_ekspor, 0), 2) , 
	               ',"hargaPenyerahan":', round(coalesce(x.harga_penyerahan, 0), 4) ,  
	               ',"hargaPerolehan":', round(coalesce(x.harga_perolehan, 0), 2) ,  
	               ',"isiPerKemasan":', round(coalesce(x.isi_per_kemasan, 0), 2) ,  
	               ',"jumlahKemasan":', round(coalesce(x.jumlah_kemasan, 0), 2) , 
	               ',"jumlahSatuan":', round(coalesce(x.jumlah_satuan, 0), 4) ,  
	               ',"kodeAsalBahanBaku":"', coalesce(x.kode_asal_barang, '0') , '"', 
	               ',"kodeAsalBarang":"', coalesce(x.kode_asal_barang, '0') , '"', 
	               ',"kodeBarang":"', coalesce(x.kode_barang, '-') , '"',
	               ',"kodeDokumen":"261"',
	               ',"kodeJenisKemasan":"', coalesce(x.kode_kemasan, '--') , '"',
	               ',"kodeNegaraAsal":"', coalesce(x.kode_negara_asal, 'ID') , '"',
	               ',"kodeSatuanBarang":"', coalesce(x.kode_satuan, 'PCE') , '"',
	               ',"merk":"', case when coalesce(x.merek, '') in ('', '-') then '--' else x.merek end , '"',
	               ',"ndpbm":', round(coalesce(x.ndpbm, 0), 4) , 
	               ',"netto":', round(coalesce(x.netto, 0), 4) , 
	               ',"nilaiBarang":', round(coalesce(x.nilai_barang, 0), 2) ,  
	               ',"nilaiJasa":', round(coalesce(x.nilai_jasa, 0), 2) ,  
	               ',"posTarif":"', coalesce(x.hs, '') , '"',
	               ',"seriBarang":', coalesce(x.seri_barang, 1) , 
	               ',"spesifikasiLain":"', coalesce(x.spesifikasi_lain, '-') , '"',
	               ',"tipe":"', case when coalesce(x.tipe, '') in ('', '-') then '--' else x.tipe end , '"',
	               ',"uangMuka": 0.0000',
	               ',"ukuran":"', coalesce(x.ukuran, '-') , '"',
	               ',"uraian":"', coalesce(x.uraian, '-') , '"',
	               ',"bahanBaku":', coalesce(
						   ( SELECT CONCAT( '[', GROUP_CONCAT( CONCAT(
							   '{"cif":', round(coalesce(y.cif, 0), 2) , 
								',"cifRupiah":', round(coalesce(y.cif_rupiah, 0), 2) ,  
								',"flagTis":"', coalesce(y.flag_tis, 'T') , '"',
								',"hargaPenyerahan":', round(coalesce(y.harga_penyerahan, 0), 4) , 
								',"hargaPerolehan":', round(coalesce(y.harga_perolehan, 0), 2) , 	 
								',"isiPerKemasan":', round(coalesce(y.isi_per_kemasan, 0), 2) , 
								',"jumlahSatuan":', round(coalesce(y.jumlah_satuan, 0), 4) , 								
								',"kodeAsalBahanBaku":"', coalesce(y.kode_asal_bahan_baku, '0') , '"',
								',"kodeBarang":"', coalesce(y.kode_barang, '-') , '"',
								',"kodeDokAsal":"', coalesce(y.kode_dokumen_asal, '40') , '"',
								',"kodeDokumen":"261"',
								',"kodeKantor":"', coalesce(y.kode_kantor_asal, '') , '"',
								',"kodeSatuanBarang":"', coalesce(y.kode_satuan, 'PCE') , '"',
								',"merkBarang":"', coalesce(y.merek, '-') , '"',
								',"ndpbm":', round(coalesce(y.ndpbm, 0), 4) ,  
								',"netto":', round(coalesce(y.netto, 0), 4) , 							
								',"nilaiJasa":', round(coalesce(y.nilai_jasa, 0), 2) , 
								',"nomorDaftarDokAsal":"', coalesce(y.nomor_daftar_asal, '-') , '"',
								',"nomorDokumen":"', coalesce(y.nomor_daftar_asal, '-') , '"',
								',"posTarif":"', coalesce(y.hs, '') , '"',
								',"seriBahanBaku":', coalesce(y.seri_bahan_baku, 1) , 
								',"seriBarang":', coalesce(y.seri_barang, 1) , 
								',"seriBarangDokAsal":', coalesce(y.seri_barang_asal, 1) , 
								',"seriIjin":', coalesce(y.seri_izin, 0) , 							
								',"spesifikasiLainBarang":"', coalesce(y.spesifikasi_lain, '-') , '"',
								',"tanggalDaftarDokAsal":"', coalesce(date_format(y.tanggal_daftar_asal, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
								',"tipeBarang":"', coalesce(y.tipe, '-') , '"',
								',"ukuranBarang":"', coalesce(y.ukuran, '-') , '"',
								',"uraianBarang":"', coalesce(y.uraian, '-') , '"',
								',"bahanBakuTarif":', coalesce(
											   ( SELECT CONCAT( '[', GROUP_CONCAT( CONCAT(
												   '{"jumlahKemasan":', round(coalesce(q.jumlah_kemasan, 0), 2) , 
													',"jumlahSatuan":', round(coalesce(q.jumlah_satuan, 0), 4) , 		
													',"kodeAsalBahanBaku":"', coalesce(q.kode_asal_bahan_baku, '0') , '"',				
													',"kodeFasilitasTarif":"', coalesce(q.kode_fasilitas, '1') , '"',
													',"kodeJenisPungutan":"', coalesce(q.kode_pungutan, 'PPN') , '"',
													',"kodeJenisTarif":"', coalesce(q.kode_tarif, '1') , '"',										    
													',"kodeSatuanBarang":"', coalesce(q.kode_satuan, 'PCE') , '"',	
													',"nilaiBayar":', round(coalesce(q.nilai_bayar, 0), 2) , 
													',"nilaiFasilitas":', round(coalesce(q.nilai_fasilitas, 0), 2) ,  
													',"nilaiSudahDilunasi":', round(coalesce(q.nilai_sudah_dilunasi, 0), 2) , 
													',"seriBahanBaku":', coalesce(q.seri_bahan_baku, 1) , 
													',"tarif":', round(coalesce(q.tarif, 0), 2) , 
													',"tarifFasilitas":', round(coalesce(q.tarif_fasilitas, 0), 2) , '}'
													) ORDER BY q.kode_pungutan ), ']' )
													FROM bc_ceisa_bahanbakutarif q WHERE q.nomor_aju = y.nomor_aju AND q.seri_barang = y.seri_barang AND q.seri_bahan_baku = y.seri_bahan_baku
												), '[]') , '}'
								) ORDER BY y.seri_bahan_baku ), ']' )
								FROM bc_ceisa_bahanbaku y WHERE y.nomor_aju = x.nomor_aju AND x.seri_barang = y.seri_barang 
							), '[]'), 
 	               ',"barangTarif":', coalesce(
							( SELECT CONCAT( '[', GROUP_CONCAT( CONCAT(
			               '{"kodeJenisTarif":"', coalesce(yt.kode_tarif, '1') , '"',
							',"jumlahSatuan":', round(coalesce(yt.jumlah_satuan, 0), 4) , 
							',"kodeFasilitasTarif":"', coalesce(yt.kode_fasilitas, '1') , '"',
							',"kodeSatuanBarang":"', coalesce(x.kode_satuan, 'PCE') , '"',
							',"nilaiBayar":', round(coalesce(yt.nilai_bayar, 0), 2) , 
							',"nilaiFasilitas":', round(coalesce(yt.nilai_fasilitas, 0), 2) , 
							',"nilaiSudahDilunasi":', round(coalesce(yt.nilai_sudah_dilunasi, 0), 2) , 
							',"seriBarang":', coalesce(yt.seri_barang, 1) , 
							',"tarif":', round(coalesce(yt.tarif, 0), 2) ,  
							',"tarifFasilitas":', round(coalesce(yt.tarif_fasilitas, 0), 2) , 
							',"kodeJenisPungutan":"', coalesce(yt.kode_pungutan, 'PPN') , '"}'
							) ORDER BY yt.kode_pungutan ), ']' )  
							FROM bc_ceisa_barangtarif yt WHERE yt.nomor_aju = x.nomor_aju AND yt.seri_barang = x.seri_barang
						), '[]') , '}'
					) ORDER BY x.seri_barang ), ']' ) as json
	FROM bc_ceisa_barang x WHERE x.nomor_aju = :ls_aju
 
) dt
