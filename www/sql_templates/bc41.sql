SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
 	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"',  'S'  , '"',
				',"asuransi":',  coalesce(asuransi, '0')  , 
				',"bruto":', coalesce(bruto, '0') ,  
				',"cif":', round( coalesce(cif, 0 ) , 2) , 
				',"disclaimer":"', '1', '"',
				',"kodeJenisTpb":"', coalesce(kode_jenis_tpb, '1') , '"',
				',"freight":', coalesce(freight, '0') , 
				',"hargaPenyerahan":', coalesce(harga_penyerahan, '0') ,  
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
			   	',"jumlahKontainer":',  '0'  ,  
				',"kodeDokumen":"', coalesce(kode_dokumen, '-') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '-') , '"',
				',"kodeKantorTujuan":"', coalesce(kode_kantor_tujuan, '-') , '"',
				',"kodeTps":"', coalesce(kode_tps, ' ') , '"',
				',"kodeTujuanPengiriman":"', coalesce(kode_tujuan_pengiriman, ' ') , '"',
				',"kodeTujuanTpb":"', coalesce(kode_tujuan_tpb, ' ') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, ' ') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"',
				',"ndpbm":', coalesce(ndpbm, '0') , 
				',"netto":', coalesce(netto, '0') , 
				',"nik":"', ' ' , '"',
				',"nilaiBarang":', coalesce(nilai_barang, '0') , 
				',"nomorAju":"', coalesce(nomor_aju, '0') , '"',				
				',"idPengguna":"', ' ' , '"',
				',"seri":',  '0'  ,  
				',"tanggalAju":"', coalesce(tanggal_pernyataan, '') , '"',	 	 
				',"tanggalTtd":"', coalesce(tanggal_pernyataan, '')  , '"',
				',"volume":', coalesce(volume, '0') , 
				',"biayaTambahan":', coalesce(a.biaya_tambahan, '0') , 
				',"dasarPengenaanPajak":', round( coalesce(dasar_pengenaan_pajak, 0 ) , 2) , 
				',"ppnPajak":', round( coalesce(ppn_pajak, 0 ) , 2) , 
				',"tarifPpnPajak":', round( coalesce(tarif_ppn_pajak, 0 ) , 2) , 	
				',"kodeLokasiBayar":"', coalesce(KODE_LOKASI_BAYAR, '-') , '"',
				',"kodePembayar":"', '' , '"',
				',"userPortal":"', ' ' , '"',				
				',"biayaPengurang":', 0 ,  
				',"vd":', 0 ,  
				',"uangMuka":', 0 ,  
				',"nilaiJasa":', 0   
	           )
	     ) as json
	FROM bc_ceisa_header a WHERE nomor_aju = :ls_aju 
	
	UNION ALL	 
	   
  	SELECT 'entitas' tabel,
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
					',"nomorIdentitas":"',  coalesce(nomor_identitas,'') , '"',  
				    ',"seriEntitas":', coalesce(seri, '0') ,  
					 ',"kodeStatus":"0"',
					',"tanggalIjinEntitas":"', coalesce(tanggal_ijin_entitas,'') , '"', 
				 	',"kodeNegara":"', coalesce(kode_negara,'') , '"',  '}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ) as json
	 	FROM bc_ceisa_entitas a
	 	LEFT JOIN bc_ceisa_entidx b ON b.bc='41' AND a.kode_entitas = b.kode_entitas
	 	WHERE a.nomor_aju = :ls_aju 
	
	
	UNION ALL
	
	SELECT 'dokumen' tabel,
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"',  coalesce(kode_dokumen, '-')  , '"',
	               ',"idDokumen":"', ' ' , '"',
			       ',"kodeFasilitas":"', coalesce(kode_fasilitas, '') , '"',			
	               ',"nomorDokumen":"', coalesce(nomor_dokumen, '-') , '"',
	               ',"seriDokumen":', coalesce(seri, '0') , 
	               ',"tanggalDokumen":"', date_format(tanggal_dokumen, '%Y-%m-%d'), '"}'
	           ) ORDER BY seri 
	       ),
	       ']'
	    ) as json
	FROM bc_ceisa_dokumen WHERE nomor_aju =   :ls_aju  
	
	UNION ALL
	
   SELECT 'pengangkut' tabel,
	    CONCAT(
	       '"pengangkut": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"namaPengangkut":"',  coalesce(nama_pengangkut, '-')  , '"',
				   ',"idPengangkut":', 0 ,  						
	               ',"nomorPengangkut":"', coalesce(coalesce(nomor_pengangkut),'-') , '"',
	               ',"kodeBendera":"', coalesce(kode_bendera,' ' ) , '"',
	               ',"kodeCaraAngkut":"', coalesce(kode_cara_angkut,' ' ) , '"',
	               ',"seriPengangkut": "',  coalesce(seri, '0')	 , '" }'
	           )
	       ),
	       ']'
	    ) as json
	FROM bc_ceisa_pengangkut WHERE nomor_aju =  :ls_aju  
	
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
	           )
	       ),
	       ']'
	    ) , '"kontainer": []') as json
	FROM bc_ceisa_kontainer WHERE nomor_aju =  :ls_aju  
	
	UNION ALL
	
	SELECT 'kemasan' tabel, coalesce(
	    CONCAT(
	       '"kemasan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"jumlahKemasan":',  coalesce(round(jumlah_kemasan,0), 0)  ,  
	               ',"kodeJenisKemasan":"', coalesce(kode_kemasan, '-') , '"',
	               ',"merkKemasan":"', coalesce(merek, '') , '"',
	               ',"seriKemasan":',  coalesce(seri, 0)  , '}'
	           )
	       ),
	       ']'
	    )  , '"kemasan": []') as json
	FROM bc_ceisa_kemasan WHERE nomor_aju =  :ls_aju  
	
	UNION ALL
	

	SELECT 'pungutan' tabel, coalesce(
	    CONCAT(
	       '"pungutan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeFasilitasTarif":"', coalesce(kode_fasilitas_tarif, '') , '"',
				  ',"idPungutan":"', 0 , '"',		
	               ',"kodeJenisPungutan":"', coalesce(kode_jenis_pungutan, '') , '"',
	               ',"nilaiPungutan":',  coalesce(nilai_pungutan, 0)  , '}'
	           )
	       ),
	       ']'
	    )  , '"pungutan": []') as json
	FROM bc_ceisa_pungutan WHERE nomor_aju =   :ls_aju  
   
	
	UNION ALL
	
 	
	SELECT 'barang' tabel,
	    CONCAT( '"barang": [', GROUP_CONCAT( CONCAT( 
	               '{"asuransi":',  coalesce(x.asuransi, 0.00)   ,  
	               ',"bruto":',   coalesce(x.NETTO, 0.00)   ,  
	               ',"cif":',  round( coalesce(x.cif, 0.00 ) , 2)      , 
	               ',"diskon":',   coalesce(x.diskon, 0.00)   , 
	               ',"hargaEkspor":',   0   , 
	               ',"hargaPenyerahan":',   coalesce(x.HARGA_PENYERAHAN, 0.00)   ,  
	               ',"hargaSatuan":',   coalesce(HARGA_SATUAN, 0.00)   , 
	               ',"isiPerKemasan":',   0  ,  
	               ',"jumlahKemasan":',   coalesce(x.JUMLAH_KEMASAN, 0.00)  , 
	               ',"jumlahRealisasi":',   0   ,  
	               ',"jumlahSatuan":',   coalesce(JUMLAH_SATUAN, 0.00)   ,  
	               ',"kodeBarang":"',   coalesce(KODE_BARANG, '-')   , '"',
	               ',"kodeDokumen":"',    KODE_DOKUMEN   , '"',
	               ',"kodeJenisKemasan":"',   coalesce(kode_kemasan, '-')   , '"',
	               ',"kodeSatuanBarang":"',   coalesce(kode_satuan, '-')   , '"',
	               ',"merk":"',   coalesce(merek, '-')   , '"',
	               ',"netto":',   coalesce(x.NETTO, 0.00)   , 
	               ',"nilaiBarang":',   coalesce(x.nilai_barang, 0.00)   ,  
	               ',"posTarif":"',   coalesce(hs, '-')   , '"',
	               ',"seriBarang": ',   coalesce(SERI_BARANG, 0)    , '', 
	               ',"spesifikasiLain":"',   coalesce(SPESIFIKASI_LAIN, '-')   , '"',
	               ',"tipe":"',   coalesce(TIPE, '-')   , '"',
	               ',"ukuran":"',   coalesce(UKURAN, '-')   , '"',
	               ',"uraian":"',   coalesce(URAIAN, '-')   , '"',
	               ',"volume":',   coalesce(x.VOLUME, 0.00)   ,  
	               ',"cifRupiah":',   coalesce(x.CIF_RUPIAH, 0.00)   , 
	               ',"hargaPerolehan":',   coalesce(x.harga_perolehan, 0.00)   ,  
	               ',"kodeAsalBahanBaku":"',   (case when KODE_NEGARA_ASAL is null then 1 else 0 end)  , '"',
	               ',"ndpbm":',   coalesce(x.ndpbm, 0.00)   , 
	               ',"uangMuka":',   0  , 
	               ',"nilaiJasa":',   0  , 
	               ',"bahanBaku":',                  
			               coalesce(
						   (   SELECT  CONCAT( '[',  GROUP_CONCAT( CONCAT(
							   '{"cif":',  round( coalesce(cif, 0.00),2)   , 
								',"cifRupiah":',   coalesce(cif_rupiah, 0.00)   ,  
								',"hargaPenyerahan":',   coalesce(harga_penyerahan, 0.00)   , 
								',"hargaPerolehan":',   coalesce(harga_perolehan, 0.00)   , 	 
								',"jumlahSatuan":',   coalesce(jumlah_satuan, 0.00)   , 								
								',"kodeSatuanBarang":"',   coalesce(y.kode_satuan, ' ')   , '"',
								',"kodeAsalBahanBaku":"',   coalesce(y.kode_asal_bahan_baku, '0')   , '"',
								',"kodeBarang":"',   coalesce(y.kode_barang, '-')   , '"',
								',"kodeDokAsal":"',   coalesce(y.kode_dokumen_asal, ' ')   , '"',
								',"kodeDokumen":"',   coalesce(y.kode_dokumen_asal, ' ')   , '"',
								',"kodeKantor":"',   coalesce(y.kode_kantor_asal, ' ')   , '"',
								',"merkBarang":"',   coalesce(y.merek, ' ')   , '"',
								',"ndpbm":',   coalesce(y.ndpbm, '0')   ,  
								',"netto":',   coalesce(netto, 0.00)   , 							
								',"nomorAjuDokAsal":"',   coalesce(y.nomor_aju_asal, ' ')   , '"',
								',"nomorDaftarDokAsal":"',   coalesce(y.nomor_daftar_asal, ' ')   , '"',
								',"posTarif":"',   coalesce(y.hs, ' ')   , '"',
								',"seriBahanBaku":',   coalesce(seri_bahan_baku, 0)   , 
								',"seriBarang":',   coalesce(seri_barang, 0)   , 
								',"seriBarangDokAsal":',   coalesce(seri_barang_asal, 0)   , 
								',"seriIjin":',   coalesce(seri_izin, 0)   , 							
								',"spesifikasiLainBarang":"',   coalesce(y.spesifikasi_lain, ' ')   , '"',
								',"tanggalDaftarDokAsal":"',  coalesce(y.tanggal_daftar_asal, ' ')   , '"',
								',"tipeBarang":"',   coalesce(y.tipe, ' ')   , '"',
								',"ukuranBarang":"',   coalesce(y.ukuran, ' ')   , '"',
								',"uraianBarang":"',   coalesce(y.uraian, '-')   , '"',
								',"nilaiJasa":',   '0'  
								',"bahanBakuTarif":',   								 
											   coalesce(
											   (   SELECT CONCAT( '[',  GROUP_CONCAT( CONCAT(
												   '{"seriBahanBaku":',    coalesce(seri_barang, 0)   , 
													',"kodeJenisPungutan":"',   coalesce(kode_pungutan, ' ')  , '"',
													',"kodeAsalBahanBaku":"',   coalesce(kode_asal_bahan_baku, 0)  , '"',				
													',"kodeFasilitasTarif":"',   coalesce(q.kode_fasilitas, 0)    , '"',
													',"nilaiBayar":',   coalesce(q.nilai_bayar, 0)   , 
													',"nilaiFasilitas":',   coalesce(q.nilai_fasilitas, 0)   ,  
													',"nilaiSudahDilunasi":',   coalesce(q.nilai_sudah_dilunasi, 0)   , 
													',"tarif":',   coalesce(q.tarif, 0)   , 
													',"tarifFasilitas":',   coalesce(q.tarif_fasilitas, 0)  , 
													',"jumlahSatuan":', 0  , 
													',"kodeJenisTarif":"',   coalesce(q.kode_tarif, '1')   , '"',										    
													',"jumlahKemasan":', 0 	   
													, '}' ) ),  ']' ) AS json
													FROM bc_ceisa_bahanbakutarif q WHERE q.nomor_aju = y.nomor_aju AND q.seri_barang =  y.seri_barang and q.seri_bahan_baku = y.seri_bahan_baku 
												) ,'[]'),																			
								'}'
								)), ']' )  AS json
								FROM bc_ceisa_bahanbaku y WHERE y.nomor_aju = x.nomor_aju AND x.seri_barang= y.seri_barang 
							) ,'[]'), 
						'}')), ']' ) as json
	FROM bc_ceisa_barang x, bc_ceisa_header z WHERE z.nomor_aju = :ls_aju   AND x.nomor_aju = z.nomor_aju  
	
) dt
