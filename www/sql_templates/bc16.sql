SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"', 'S' , '"',
				',"bruto":', round(coalesce(bruto, '0'), 4) ,  
				',"cif":', round(coalesce(cif, '0'), 2) , 
				',"disclaimer":"1"',
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"kodeDokumen":"16"',
				',"kodeIncoterm":"', coalesce(kode_incoterm, 'CIF') , '"',
				',"kodeJenisNilai":"', coalesce(kode_jenis_nilai, '1') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeKantorBongkar":"', coalesce(kode_kantor_bongkar, kode_kantor, '') , '"',
				',"kodePelBongkar":"', coalesce(kode_pelabuhan_bongkar, '') , '"',
				',"kodePelMuat":"', coalesce(kode_pelabuhan_muat, '') , '"',
				',"kodePelTransit":"', coalesce(kode_pelabuhan_transit, '') , '"',
				',"kodeTps":"', coalesce(kode_tps, '') , '"',
				',"kodeTutupPu":"', coalesce(kode_tutup_pu, '11') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, 'USD') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"', 
				',"ndpbm":', round(coalesce(ndpbm, '0'), 4) , 
				',"netto":', round(coalesce(netto, '0'), 4) , 
			    ',"nomorAju":"', coalesce(nomor_aju, '') , '"',
				',"nomorBc11":"', coalesce(nomor_bc11, '000000') , '"',
				',"posBc11":"', coalesce(nomor_pos, '0000') , '"',
				',"seri":', 1 ,  		 	
				',"subposBc11":"', coalesce(nomor_sub_pos, '0000') , '"', 
				',"tanggalBc11":"', coalesce(date_format(tanggal_bc11, '%Y-%m-%d'), date_format(tanggal_pernyataan, '%Y-%m-%d')) , '"',
				',"tanggalTiba":"', coalesce(date_format(tanggal_tiba, '%Y-%m-%d'), date_format(tanggal_pernyataan, '%Y-%m-%d')) , '"',	 	
				',"tanggalTtd":"', coalesce(date_format(tanggal_pernyataan, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"'			
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
				  	case when a.kode_entitas in ('3', '7') then concat(',"kodeJenisIdentitas":"', coalesce(a.kode_jenis_identitas, '5'), '"') else '' end,
				 	case when a.kode_entitas in ('10', '7', '9') then concat(',"kodeNegara":"', coalesce(a.kode_negara, 'ID'), '"') else '' end,
				 	case when a.kode_entitas in ('3', '7') then concat(',"kodeStatus":"', coalesce(a.kode_status, '1'), '"') else '' end,
					',"namaEntitas":"', coalesce(a.nama_entitas, '-') , '"', 
					case when a.kode_entitas in ('3', '7') then concat(',"nibEntitas":"', coalesce(a.nib_entitas, ''), '"') else '' end,
					case when a.kode_entitas in ('3', '7') then concat(',"nomorIdentitas":"', coalesce(a.nomor_identitas, '-'), '"') else '' end,
				 	case when a.kode_entitas = '3' then concat(',"nomorIjinEntitas":"', coalesce(a.nomor_ijin_entitas, ''), '"') else '' end,
				    ',"seriEntitas":', coalesce(a.seri, 1) ,  
					case when a.kode_entitas = '3' and a.tanggal_ijin_entitas is not null and a.tanggal_ijin_entitas != '' then concat(',"tanggalIjinEntitas":"', date_format(a.tanggal_ijin_entitas, '%Y-%m-%d'), '"') else '' end,
					'}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ), '"entitas": []') as json
	FROM bc_ceisa_entitas a
	LEFT JOIN bc_ceisa_entidx b on b.bc = '16' and b.kode_entitas = a.kode_entitas
	WHERE nomor_aju = :ls_aju 
	
	UNION ALL
	 
	SELECT 'dokumen' tabel, coalesce(
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"', coalesce(kode_dokumen, '380') , '"',
				   ',"kodeFasilitas":"', coalesce(kode_fasilitas, '') , '"',
				   ',"kodeIjin":"', coalesce(kode_ijin, '') , '"',
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
	               '{"kodeBendera":"', coalesce(kode_bendera, 'ID') , '"',
	               ',"kodeCaraAngkut":"', coalesce(kode_cara_angkut, '1') , '"',
	               ',"namaPengangkut":"', coalesce(nama_pengangkut, '-') , '"',
	               ',"nomorPengangkut":"', coalesce(nomor_pengangkut, '-') , '"',
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
	               ',"jumlahKemasan":', round(coalesce(x.jumlah_kemasan, 0), 2) , 
	               ',"jumlahSatuan":', round(coalesce(x.jumlah_satuan, 0), 4) , 
	               ',"kodeBarang":"', coalesce(x.kode_barang, '-') , '"',
	               ',"kodeJenisKemasan":"', coalesce(x.kode_kemasan, 'PK') , '"',
	               ',"kodeJenisNilai":"', coalesce(x.kode_jenis_nilai, '1') , '"',
	               ',"kodeKategoriBarang":"', coalesce(x.kode_kategori_barang, '') , '"',
	               ',"kodeNegaraAsal":"', coalesce(x.kode_negara_asal, 'ID') , '"',
	               ',"kodeSatuanBarang":"', coalesce(x.kode_satuan, 'PCE') , '"',
	               ',"merk":"', case when coalesce(x.merek, '') in ('', '-') then '--' else x.merek end , '"',
	               ',"netto":', round(coalesce(x.netto, 0), 4) , 
	               ',"posTarif":"', coalesce(x.hs, '') , '"',
	               ',"seriBarang":', coalesce(x.seri_barang, 1) , 
	               ',"spesifikasiLain":"', coalesce(x.spesifikasi_lain, '-') , '"',
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
