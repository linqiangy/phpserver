<?php
/**
 * PHP Rpc 权限控制
 * 控制力度可以达到方法级别
 * 控制方法
 * 服务名.类名.方法名 = array(ip,ip,...)
 */
return array(
    // 订单服务( 对应config/main.php中workers['Order'] )
    // 这里只允许172.20.4.59/60掉用
	'Order' => array(
		'172.20.4.59',
		'172.20.4.60',
	),
	// 订单的Cart_User类
    // Order服务的Cart_User类只能被172.10.6.78/79调用
    'Order.Cart_User' => array(
        '172.10.6.78',
    	'172.10.6.79',
    ),
	// Order服务的Cart_User类的getUserByUid方法只能被172.11.8.93调用
	'Order.Cart_User.getUserByUid' => array(
		'172.11.8.93'
	),
    
    // PromoCard服务( 对应config/main.php中workers['PromoCard'] )
    // 直接控制 PromoCard.PromoCardSharding.getUnusePromoCardByUid 方法能被哪些ip调用
    // 只有172.20.4.59才能调用 PromoCard.PromoCardSharding.getUnusePromoCardByUid
    // 其它ip可以调用除了PromoCard.PromoCardSharding.getUnusePromoCardByUid的任何接口
    'PromoCard.PromoCardSharding.getUnusePromoCardByUid' => array(
        '172.20.4.59',
    ),
	
	// ProductLib服务的Advertising_Read_Item类只能被172.30.4.56调用
    // 其它ip可以调用除了ProductLib.Advertising_Read_Item类以外的任何类的方法
	'ProductLib.Advertising_Read_Item' => array(
		'172.30.4.56'	
	),
	
);
