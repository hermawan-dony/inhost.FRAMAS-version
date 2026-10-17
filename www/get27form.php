<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get AJU Data from CEISA 4.0</title>
    <!-- Link ke Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<!-- Modal untuk input Nomor AJU -->
<div class="modal fade" id="ajuModal" tabindex="-1" aria-labelledby="ajuModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ajuModalLabel">Get AJU data from CEISA 4.0</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info" role="alert">
          <p>Silahkan masukkan Nomor AJU!</p>
          <p>Selain menginput manual Nomor AJU, Anda dapat juga melakukan scanning SPPB barcode.</p>
        </div>

        <!-- Form input AJU -->
        <form action="process_aju.php" method="POST">
          <div class="mb-3">
            <label for="ajuNumber" class="form-label">AJU No.<span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="ajuNumber" name="ajuNumber" required>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">Submit</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Button to trigger modal -->
<div class="container mt-5">
  <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#ajuModal">
    Retrieve by AJU No.
  </button>
</div>

<!-- Bootstrap JS dan dependensi -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.10.2/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.min.js"></script>
</body>
</html>
