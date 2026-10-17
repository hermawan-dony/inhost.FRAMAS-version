<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Mutasi Mesin & Peralatan</title>


  <!-- Buttons -->
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

  <!-- SearchBuilder -->
  <script src="https://cdn.datatables.net/searchbuilder/1.8.2/js/dataTables.searchBuilder.min.js"></script>
  <script src="https://cdn.datatables.net/datetime/1.5.2/js/dataTables.dateTime.min.js"></script>
  
    <!-- DataTables Core -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">

  <!-- Buttons -->
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

  <!-- SearchBuilder -->
  <link rel="stylesheet" href="https://cdn.datatables.net/searchbuilder/1.8.2/css/searchBuilder.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/datetime/1.5.2/css/dataTables.dateTime.min.css">

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

</head>
<body>
  <h2>Laporan Pertanggungjawaban Mutasi Mesin dan Peralatan</h2>

  <table id="laporan" class="display nowrap" style="width:100%">
    <thead>
      <tr>
        <th>No</th>
        <th>Nomor Aset</th>
        <th>Uraian</th>
        <th>Sat</th>
        <th>Saldo Awal</th>
        <th>Pemasukan</th>
        <th>Pengeluaran</th>
        <th>Penyesuaian</th>
        <th>Saldo Buku</th>
        <th>Stock Opname</th>
        <th>Selisih</th>
        <th>Keterangan</th>
      </tr>
    </thead>
    <tbody>
      <tr><td>1</td><td>A842607</td><td>C2R20501 FORMING ROLL</td><td>PCE</td><td>5</td><td>0</td><td>0</td><td>0</td><td>5</td><td>0</td><td>0</td><td>-</td></tr>
      <tr><td>2</td><td>103337</td><td>GRAPHITE CUP</td><td>PCS</td><td>405</td><td>0</td><td>0</td><td>0</td><td>405</td><td>0</td><td>0</td><td>-</td></tr>
      <tr><td>3</td><td>1036754</td><td>SLIDE RING</td><td>PCE</td><td>10</td><td>0</td><td>2</td><td>0</td><td>8</td><td>0</td><td>0</td><td>-</td></tr>
      <tr><td>4</td><td>400158</td><td>BEARING 6306 ZZ NSK</td><td>PCE</td><td>12</td><td>3</td><td>0</td><td>0</td><td>15</td><td>0</td><td>0</td><td>-</td></tr>
      <tr><td>5</td><td>400236</td><td>BEARING 6310 ZZ SKF</td><td>PCE</td><td>1</td><td>0</td><td>0</td><td>0</td><td>1</td><td>0</td><td>0</td><td>-</td></tr>
    </tbody>
  </table>

  <script>
    $(document).ready(function() {
      $('#laporan').DataTable({
        dom: 'QBfrtip', // Q = SearchBuilder aktif
        buttons: ['excelHtml5', 'print'],
        scrollX: true
      });
    });
  </script>
</body>
</html>
