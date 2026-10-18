SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"',  'S'  , '"',
				',"asuransi":',  coalesce(asuransi, '0')  , 
				',"bruto":', coalesce(bruto, '0') ,  
				',"cif":', round(coalesce(cif, '0'),2) , 
				',"fob":', round(coalesce(fob, '0'),2) , 
				',"freight":', coalesce(freight, '0') , 				
				',"hargaPenyerahan":', coalesce(harga_penyerahan, '0') , 
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"jumlahKontainer":',  coalesce((SELECT count(1) FROM bc_ceisa_kontainer WHERE nomor_aju = h.nomor_aju), 0) , 
				',"kodeAsuransi":"',  coalesce(kode_asuransi, '')   , '"',
			 	',"kodeDokumen":"',  '613' , '"',
			   	',"kodeIncoterm":"',  coalesce(kode_incoterm, '')   , '"',
				',"kodeJenisNilai":"', coalesce(kode_jenis_nilai, '') , '"',
				',"kodeJenisProsedur":"', coalesce(kode_jenis_prosedur, '') , '"',
				',"kodeJenisTpb":"', coalesce(kode_jenis_tpb, '') , '"',
				',"kodeTujuanTpb":"', coalesce(kode_tujuan_tpb, '') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeLokasiBayar":"', coalesce(kode_lokasi_bayar, '') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, '') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"', 
				',"ndpbm":', coalesce(ndpbm, '0') , 
				',"netto":', coalesce(netto, '0') , 
				',"nik":"', ' ' , '"',
				',"nilaiBarang":', round(coalesce(nilai_barang, '0') ,2),
				',"nilaiIncoterm":', round(coalesce(nilai_incoterm, '0') ,2),
			    ',"nomorAju":"', coalesce(nomor_aju, '') , '"',
				',"seri":',  '1'  ,  		 	
				',"tanggalTtd":"', coalesce(tanggal_pernyataan, '')  , '"',
				',"biayaTambahan":', coalesce(biaya_tambahan, '0') , 
			 	',"biayaPengurang":', coalesce(biaya_pengurang, '0') , 
			 	',"vd":', coalesce(vd, '0') , 
			 	',"uangMuka":', coalesce(uang_muka, '0') , 
			 	',"nilaiJasa":', coalesce(nilai_jasa, '0') , 
			 	',"volume":', coalesce(volume, '0') , 
				',"dasarPengenaanPajak":', coalesce(dasar_pengenaan_pajak, '0') , 
				',"flagVd":"', coalesce(flag_vd, '') , '"',
				',"kodeKenaPajak":"',   coalesce(kode_jasa_kena_pajak, ' ')    , '"'			
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
				 	',"kodeNegara":"', coalesce(kode_negara,'') , '"',  '}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ), '"entitas": []') as json
	 	FROM bc_ceisa_entitas a
	 	LEFT JOIN bc_ceisa_entidx b ON b.bc='613' AND a.kode_entitas = b.kode_entitas
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
	               '{"asuransi":',  coalesce(x.asuransi, 0.00)   ,  
	               ',"bruto":',   coalesce(x.bruto, 0.00)   ,  
	               ',"cif":',   round(coalesce(x.cif, 0.00),2)  , 
				 ',"fob":',   round(coalesce(x.fob, 0.00),2)   , 
	               ',"freight":',   coalesce(x.freight, 0.00)   , 		
	               ',"diskon":',   coalesce(x.diskon, 0.00)   , 
				  ',"kodeNegaraAsal":"',   coalesce(kode_negara_asal, ' ')   , '"',
	               ',"kodePerhitungan":"',   coalesce(kode_perhitungan * 1, '0')   , '"',
				 ',"kodeKategoriBarang":"', coalesce(kode_kategori_barang, '')  , '"',				
	               ',"hargaEkspor":', coalesce(harga_ekspor, '0')   , 
			     ',"isiPerKemasan":',   coalesce(round(isi_per_kemasan,0), '0')      ,  
	               ',"hargaPenyerahan":',   coalesce(x.harga_penyerahan, 0.00)   ,  
	               ',"hargaSatuan":',   coalesce(harga_satuan, 0.00)   , 
	               ',"jumlahKemasan":',   coalesce(x.jumlah_kemasan, 0.00)  , 
	               ',"jumlahRealisasi":',  coalesce(x.jumlah_realisasi, 0.00)  ,   
	               ',"jumlahSatuan":',   coalesce(jumlah_satuan, 0.00)   ,  
	               ',"kodeBarang":"',   coalesce(kode_barang, '')   , '"',
	               ',"kodeDokumen":"',    '613'   , '"',
	               ',"kodeJenisKemasan":"',   coalesce(kode_kemasan, '-')   , '"',
	               ',"kodeSatuanBarang":"',   coalesce(kode_satuan, '-')   , '"',
	               ',"merk":"',   coalesce(merek, '-')   , '"',
	               ',"netto":',   coalesce(x.netto, 0.00)   , 
	               ',"nilaiBarang":',   round(coalesce(nilai_barang, 0.00) ,2)  ,  
	               ',"posTarif":"',   coalesce(hs, '')   , '"',
	               ',"seriBarang":',   coalesce(seri_barang, 0)   ,  
	               ',"spesifikasiLain":"',   coalesce(spesifikasi_lain, '')   , '"',
	               ',"tipe":"',   coalesce(tipe, '-')   , '"',
	               ',"ukuran":"',   coalesce(ukuran, '-')   , '"',
	               ',"uraian":"',   coalesce(uraian, '-')   , '"',
	               ',"volume":',   coalesce(x.volume, 0.00)   ,  
	               ',"cifRupiah":',   coalesce(x.cif_rupiah, 0.00)   , 
	               ',"hargaPerolehan":',   coalesce(x.harga_perolehan, 0.00)   ,  
	               ',"kodeAsalBahanBaku":"',  coalesce(kode_asal_barang, '0') , '"', 
	               ',"ndpbm":',   coalesce(ndpbm, 0.00)   , 
	               ',"uangMuka":',   0   , 
	               ',"nilaiJasa":',   coalesce(nilai_jasa, '0')   , 
			     ',"nilaiTambah":',   coalesce(nilai_tambah, '0')   , 		
				 ',"barangDokumen":',
				 coalesce((SELECT CONCAT('[', GROUP_CONCAT(CONCAT(
				     '{"seriDokumen":', coalesce(seri_dokumen, '1'),
				     ',"seriIzin":"', coalesce(seri_izin, ''), '"}'
				 ) ORDER BY seri_dokumen), ']')
				 FROM bc_ceisa_barangdokumen bd WHERE bd.nomor_aju = x.nomor_aju AND bd.seri_barang = x.seri_barang), '[]'),
	               ',"barangTarif":',                  
			               coalesce((SELECT  CONCAT( '[',  GROUP_CONCAT( CONCAT(
			               '{"kodeJenisTarif":"',  coalesce(kode_tarif, '1')   , '"',
							',"jumlahSatuan":',   coalesce(jumlah_satuan, 0.00)   , 
							',"kodeFasilitasTarif":"',   coalesce(kode_fasilitas, 0)     , '"',
							',"kodeSatuanBarang":"',   coalesce(x.KODE_SATUAN, '-')   , '"',
							',"nilaiBayar":',   coalesce(nilai_bayar, 0.00)   , 
							',"nilaiFasilitas":',   coalesce(nilai_fasilitas, 0.00)   , 
							',"nilaiSudahDilunasi":',   coalesce(nilai_sudah_dilunasi, 0.00)   , 
							',"seriBarang":',   coalesce(seri_barang, '0')   , 
							',"tarif":',   coalesce(tarif, '0')   ,  
							',"tarifFasilitas":',   coalesce(tarif_fasilitas, '0')   , 
							',"kodeJenisPungutan":"',   coalesce(kode_pungutan, '-')    , '"}'
							) ORDER BY kode_pungutan ), ']' )  
							FROM bc_ceisa_barangtarif y WHERE x.nomor_aju = y.nomor_aju AND x.seri_barang= y.seri_barang ) ,'[]'), '}')), ']' ) as json
	FROM bc_ceisa_barang x WHERE nomor_aju = :ls_aju
) dt
