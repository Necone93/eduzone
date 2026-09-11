<?php
session_start();
require_once __DIR__ . '/admin_config.php';
if (!ez_is_admin()) { exit('⛔'); }
require __DIR__.'/db_connect.php';

$id=(int)($_GET['id']??0);
if(!$id) exit('Nedostaje id.');

$conn->begin_transaction();
try{
  $conn->query("DELETE FROM odgovori WHERE pitanje_id IN (SELECT id FROM pitanja WHERE test_id=".$id.")");
  $st=$conn->prepare("DELETE FROM pitanja WHERE test_id=?"); $st->bind_param("i",$id); $st->execute(); $st->close();
  $st=$conn->prepare("DELETE FROM testovi WHERE id=?");    $st->bind_param("i",$id); $st->execute(); $st->close();
  $conn->commit();
  header("Location: tests_admin.php?msg=Test #$id obrisan."); exit;
}catch(Exception $e){
  $conn->rollback(); echo "Greška: ".$e->getMessage();
}
