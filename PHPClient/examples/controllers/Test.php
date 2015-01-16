<?php
require __DIR__.'/../../Vendor/Bootstrap/Autoloader.php';
\Bootstrap\Autoloader::instance()->addRoot(__DIR__.'/../../../')->init();
require __DIR__.'/../config/PHPClient.php';
\PHPClient\Text::config((array)new \Config\PHPClient);
/*
$product_id = rand(1,3000);
$data=\PHPClient\Text::inst('inventoryService')->setClass('Iwc')->getSellableByProductIdMaster($product_id);
var_dump($data);
$data=\PHPClient\Text::inst('inventoryService')->setClass('Iwc')->getSellableByProductIdRead($product_id);


var_dump($data);
*/
$product_sku = array();
for($n=rand(2,100), $i=1; $i < $n; $i++)
{
   $product_sku[] = 'TM'.rand(10000000000,12000000000);
};

$data=\PHPClient\Text::inst('inventoryService')->setClass('Iwc')->getInventoryBySkus($product_sku);
var_dump($data);


$product_sku = array();
for($n=rand(2,100), $i=1; $i < $n; $i++)
{
   $product_sku[] = 'TM'.rand(10000000000,12000000000);
};


$data=\PHPClient\Text::inst('inventoryService')->setClass('Iwc')->getWarehouseInventoryBySku($product_sku);


var_dump($data);
