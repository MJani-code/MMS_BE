<?php
require_once('config.php');

try {
    // Kapcsolódás az adatbázishoz PDO-val
    //$conn = new PDO("mysql:host=host;dbname=db", $user, $password);
    $conn = new PDO("mysql:host=".host.";dbname=".db, user, pwd);
    // Beállítjuk az adatbázis kapcsolat karakterkódolását UTF-8-ra (opcionális, de ajánlott)
    $conn->exec("set names utf8");
    // Beállítjuk a hibakódot és kivételeket dob, ha hiba történik
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Egyszerű lekérdezés példa
    $sql = "SELECT * FROM users";
    //$result = $conn->query($sql);

    $statement = $conn->prepare("SELECT * FROM users");
    $statement->execute();
    $result = $statement->fetchAll(PDO::FETCH_ASSOC);


    if ($result) {
        //Kiírjuk a lekérdezés eredményét
        //print_r($result);
    } else {
        echo "Nincs eredmény.";
    }

} catch (PDOException $e) {
    die("Sikertelen kapcsolódás: " . $e->getMessage());
}


?>