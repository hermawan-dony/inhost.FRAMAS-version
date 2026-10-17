<?php
// Mulai session
session_start();

 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Dashboard</title>
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
        .dashboard-container {
            background-color: #fff;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            width: 400px;
            text-align: center;
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        .welcome-msg {
            margin-bottom: 20px;
        }
        a {
            text-decoration: none;
            background-color: #d9534f;
            color: white;
            padding: 10px;
            border-radius: 4px;
            display: inline-block;
        }
        a:hover {
            background-color: #c9302c;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <h2>Warehouse Dashboard</h2>
        <p class="welcome-msg">Selamat datang, <?php echo $_SESSION['username']; ?>!</p>
        <a href="logout.php">Logout</a>
    </div>
</body>
</html>
