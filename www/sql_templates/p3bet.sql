SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"', 'S' , '"',
				',"asuransi":', round(coalesce(asuransi, 0), 2) , 
				',"bruto":', round(coalesce(bruto, 0), 4) ,  
				',"cif":', round(coalesce(cif, 0), 2) , 
				',"fob":', round(coalesce(fob, 0), 2) , 
				',"freight":', round(coalesce(freight, 0), 2) , 
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"jumlahKontainer":', coalesce((SELECT count(1) FROM bc_ceisa_kontainer WHERE nomor_aju = h.nomor_aju), 0) , 
				',"kodeCaraBayar":"', coalesce(kode_cara_bayar, '1') , '"',
				',"kodeCaraDagang":"', coalesce(kode_cara_dagang, '1') , '"',
				',"kodeDokumen":"331"',
				',"kodeIncoterm":"', coalesce(kode_incoterm, 'FOB') , '"',
				',"kodeJenisEkspor":"', coalesce(kode_jenis_ekspor, '1') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeKategoriEkspor":"', coalesce(kode_kategori_ekspor, '1') , '"',
				',"kodePelBongkar":"', coalesce(kode_pelabuhan_bongkar, kode_pelabuhan_tujuan, '') , '"',
				',"kodePelMuat":"', coalesce(kode_pelabuhan_muat, '') , '"',
				',"kodePelTujuan":"', coalesce(kode_pelabuhan_tujuan, '') , '"',
				',"kodeTps":"', coalesce(kode_tps, '') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, 'USD') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"', 
				',"ndpbm":', round(coalesce(ndpbm, 0), 4) , 
				',"netto":', round(coalesce(netto, 0), 4) , 
				',"nilaiBarang":', round(coalesce(nilai_barang, 0), 2) , 
			    ',"nomorAju":"', coalesce(nomor_aju, '') , '"',
				',"seri":', 1 ,  		 	
				',"tanggalMuat":"', coalesce(date_format(tanggal_muat, '%Y-%m-%d'), date_format(tanggal_pernyataan, '%Y-%m-%d')) , '"',
				',"tanggalTtd":"', coalesce(date_format(tanggal_pernyataan, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
				',"tempatStuffing":"', coalesce(tempat_stuffing, '-') , '"'
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
				 	case when a.kode_entitas = '8' then concat(',"kodeNegara":"', coalesce(a.kode_negara, 'SG'), '"') else '' end,
				 	case when a.kode_entitas = '7' then concat(',"kodeStatus":"', coalesce(a.kode_status, '1'), '"') else '' end,
					',"namaEntitas":"', coalesce(a.nama_entitas, '-') , '"', 
					case when a.kode_entitas in ('3', '7') then concat(',"nibEntitas":"', coalesce(a.nib_entitas, ''), '"') else '' end,
					case when a.kode_entitas in ('3', '7') then concat(',"nomorIdentitas":"', coalesce(a.nomor_identitas, '-'), '"') else '' end,
				 	case when a.kode_entitas in ('3', '7') then concat(',"nomorIjinEntitas":"', coalesce(a.nomor_ijin_entitas, ''), '"') else '' end,
				    ',"seriEntitas":', coalesce(a.seri, 1) ,  
					case when a.kode_entitas = '7' and a.tanggal_ijin_entitas is not null and a.tanggal_ijin_entitas != '' then concat(',"tanggalIjinEntitas":"', date_format(a.tanggal_ijin_entitas, '%Y-%m-%d'), '"') else '' end,
					'}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ), '"entitas": []') as json
	FROM bc_ceisa_entitas a
	LEFT JOIN bc_ceisa_entidx b on b.bc = '331' and b.kode_entitas = a.kode_entitas
	WHERE nomor_aju = :ls_aju 
	
	UNION ALL
	 
	SELECT 'dokumen' tabel, coalesce(
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"', coalesce(kode_dokumen, '33') , '"',
				   case when coalesce(kode_fasilitas, '') != '' then concat(',"kodeFasilitas":"', kode_fasilitas, '"') else '' end,
				   case when coalesce(kode_ijin, '') != '' then concat(',"kodeIjin":"', kode_ijin, '"') else '' end,
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
	               ',"fob":', round(coalesce(x.fob, 0), 2) , 
	               ',"jumlahKemasan":', round(coalesce(x.jumlah_kemasan, 0), 2) , 
	               ',"jumlahSatuan":', round(coalesce(x.jumlah_satuan, 0), 4) , 
	               ',"kodeBarang":"', coalesce(x.kode_barang, '-') , '"',
	               ',"kodeDokumen":"331"',
	               ',"kodeJenisKemasan":"', coalesce(x.kode_kemasan, 'PK') , '"',
	               ',"kodeSatuanBarang":"', coalesce(x.kode_satuan, 'PCE') , '"',
	               ',"merk":"', case when coalesce(x.merek, '') in ('', '-') then '--' else x.merek end , '"',
	               ',"ndpbm":', round(coalesce(x.ndpbm, 0), 4) , 
	               ',"netto":', round(coalesce(x.netto, 0), 4) , 
	               ',"nilaiBarang":', round(coalesce(x.nilai_barang, 0), 2) , 
	               ',"nilaiDevisa":', round(coalesce(x.nilai_devisa, 0), 2) , 
	               ',"posTarif":"', coalesce(x.hs, '') , '"',
	               ',"seriBarang":', coalesce(x.seri_barang, 1) , 
	               ',"spesifikasiLain":"', coalesce(x.spesifikasi_lain, '-') , '"',
	               ',"tipe":"', case when coalesce(x.tipe, '') in ('', '-') then '--' else x.tipe end , '"',
	               ',"ukuran":"', coalesce(x.ukuran, '-') , '"',
	               ',"uraian":"', coalesce(x.uraian, '-') , '"',
	               ',"barangDokumen": []',
	               ',"barangTarif": []',
	               ',"barangPemilik": []',
	               '}'
	           ) ORDER BY x.seri_barang
	       ),
	       ']'
	    ), '"barang": []') as json
	FROM bc_ceisa_barang x WHERE nomor_aju = :ls_aju

) dt
