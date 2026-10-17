<?php
// Mulai session
session_start();

// Cek apakah form sudah disubmit
if (isset($_POST['login'])) {
    // Masukkan username dan password yang valid (contoh)
    $username = "warehouseadmin";
    $password = "securepassword123";

    // Ambil data dari form
    $inputUsername = $_POST['username'];
    $inputPassword = $_POST['password'];

    // Verifikasi username dan password
    if ($inputUsername === $username && $inputPassword === $password) {
        // Jika benar, simpan session dan redirect ke halaman dashboard
        $_SESSION['username'] = $username;
        header("Location: dashboard.php");
        exit();
    } else {
        // Jika salah, beri pesan error
        $error = "Username atau Password salah!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: #fff;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            width: 300px;
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        input[type="submit"] {
            width: 100%;
            padding: 10px;
            background-color: #0056b3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        input[type="submit"]:hover {
            background-color: #004494;
        }
        .error {
            color: red;
            text-align: center;
            margin-bottom: 10px;
        }
        .warehouse-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .warehouse-logo img {
            width: 100px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="warehouse-logo">
            <img src="warehouse-logo.png" alt="Warehouse Logo">
        </div>
        <h2>Warehouse Login</h2>
        <?php if (isset($error)) { ?>
            <div class="error"><?php echo $error; ?></div>
        <?php } ?>
        <form action="login.php" method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="submit" name="login" value="Login">
        </form>
    </div>
</body>
</html>
