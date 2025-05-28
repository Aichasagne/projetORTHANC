<?php

class Database {
    private $host = "localhost";
    private $db_name = "projet_medical";
    private $username = "root";
    private $password = "";
    private $conn = null;

    public function connect() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
            return $this->conn;
        } catch (PDOException $e) {
            echo "Erreur de connexion : " . $e->getMessage();
            return null;
        }
    }
}
?>