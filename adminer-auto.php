<?php
session_start();
if (empty($_SESSION['dp_auth'])) {
    header('Location: /dp');
    exit;
}

function adminer_object() {
    class AdminerCustom extends Adminer {
        function credentials() {
            return ['rm-d9j4584f890612j7vro.mysql.ap-southeast-5.rds.aliyuncs.com:63306', 'ceisarwuser', 'CiT8T2#h4Jd#3wer$9d36hd'];
        }
        function database() {
            return 'ceisa0400db';
        }
        function login($login, $password) {
            return true;
        }
        function name() {
            return 'CEISA 4.0 Multi-Company DB';
        }
    }
    return new AdminerCustom;
}

include __DIR__ . '/db-admin.php';