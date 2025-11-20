-- Nine Market Database Backup
-- Generated: 2025-11-14 22:02:53
SET FOREIGN_KEY_CHECKS=0;

-- Table: abonos
DROP TABLE IF EXISTS `abonos`;
CREATE TABLE `abonos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `credito_id` int(11) NOT NULL,
  `monto_bs` decimal(12,2) NOT NULL,
  `tasa_cambio` decimal(10,2) NOT NULL,
  `equivalente_usd` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `credito_id` (`credito_id`),
  CONSTRAINT `abonos_ibfk_1` FOREIGN KEY (`credito_id`) REFERENCES `creditos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: abonos

-- Table: categorias
DROP TABLE IF EXISTS `categorias`;
CREATE TABLE `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: categorias
INSERT INTO `categorias` VALUES("1","Bebidas","2025-11-09 17:29:39","2025-11-09 17:29:39");
INSERT INTO `categorias` VALUES("2","Alimentos","2025-11-09 17:29:39","2025-11-09 17:29:39");
INSERT INTO `categorias` VALUES("3","Limpieza","2025-11-09 17:29:39","2025-11-09 17:29:39");
INSERT INTO `categorias` VALUES("4","Otros","2025-11-09 17:29:39","2025-11-09 17:29:39");

-- Table: clientes
DROP TABLE IF EXISTS `clientes`;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `clasificacion` enum('A','B','C') DEFAULT 'C',
  `limite_credito` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: clientes
INSERT INTO `clientes` VALUES("1","Consumidor Final",NULL,NULL,"C","0.00","2025-11-09 17:29:39","2025-11-09 17:29:39");

-- Table: configuracion
DROP TABLE IF EXISTS `configuracion`;
CREATE TABLE `configuracion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_negocio` varchar(255) DEFAULT 'Nine Market',
  `tasa_cambio` decimal(10,2) DEFAULT 36.00,
  `iva` decimal(5,2) DEFAULT 16.00,
  `dias_vencimiento_credito` int(11) DEFAULT 7,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: configuracion
INSERT INTO `configuracion` VALUES("1","Nine Market","236.50","16.00","7","2025-11-09 17:29:39","2025-11-14 21:31:39");

-- Table: costos_fijos
DROP TABLE IF EXISTS `costos_fijos`;
CREATE TABLE `costos_fijos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `tipo` enum('mensual','semanal','diario') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data: costos_fijos
INSERT INTO `costos_fijos` VALUES("1","Alquiler local","500.00","mensual","2025-11-14 20:56:01","2025-11-14 20:56:01");
INSERT INTO `costos_fijos` VALUES("2","Servicios (luz, agua)","150.00","mensual","2025-11-14 20:56:01","2025-11-14 20:56:01");
INSERT INTO `costos_fijos` VALUES("3","Salarios","1200.00","mensual","2025-11-14 20:56:01","2025-11-14 20:56:01");
INSERT INTO `costos_fijos` VALUES("4","Mantenimiento","50.00","semanal","2025-11-14 20:56:01","2025-11-14 20:56:01");
INSERT INTO `costos_fijos` VALUES("5","Transporte","20.00","diario","2025-11-14 20:56:01","2025-11-14 20:56:01");

-- Table: creditos
DROP TABLE IF EXISTS `creditos`;
CREATE TABLE `creditos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `monto_usd` decimal(12,2) NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `cliente_id` (`cliente_id`),
  CONSTRAINT `creditos_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `creditos_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: creditos

-- Table: detalle_ventas
DROP TABLE IF EXISTS `detalle_ventas`;
CREATE TABLE `detalle_ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL,
  `precio_bs` decimal(12,2) NOT NULL,
  `precio_usd` decimal(12,2) NOT NULL,
  `subtotal_bs` decimal(12,2) NOT NULL,
  `subtotal_usd` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `detalle_ventas_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detalle_ventas_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: detalle_ventas
INSERT INTO `detalle_ventas` VALUES("1","1","5","1","34.80","0.97","34.80","0.97","2025-11-14 20:14:12");
INSERT INTO `detalle_ventas` VALUES("2","1","1","1","11.60","0.32","11.60","0.32","2025-11-14 20:14:12");
INSERT INTO `detalle_ventas` VALUES("3","1","3","1","23.20","0.64","23.20","0.64","2025-11-14 20:14:12");

-- Table: productos
DROP TABLE IF EXISTS `productos`;
CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `codigo_barras` varchar(100) DEFAULT NULL,
  `imagen` varchar(255) DEFAULT NULL,
  `categoria_id` int(11) NOT NULL,
  `costo_bs` decimal(12,2) DEFAULT 0.00,
  `precio_bs` decimal(12,2) NOT NULL,
  `precio_usd` decimal(12,2) NOT NULL,
  `stock` int(11) DEFAULT 0,
  `stock_minimo` int(11) DEFAULT 3,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_barras` (`codigo_barras`),
  KEY `categoria_id` (`categoria_id`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: productos
INSERT INTO `productos` VALUES("1","Coca-Cola 355ml","7501055300013",NULL,"1","8.00","11.60","0.32","49","3","2025-11-09 17:29:40","2025-11-14 20:14:12");
INSERT INTO `productos` VALUES("2","Pepsi 355ml","7501055301014",NULL,"1","7.50","11.60","0.32","40","3","2025-11-09 17:29:40","2025-11-09 17:29:40");
INSERT INTO `productos` VALUES("3","Arroz 1kg","7501234567890",NULL,"2","16.00","23.20","0.64","29","3","2025-11-09 17:29:40","2025-11-14 20:14:12");
INSERT INTO `productos` VALUES("4","Aceite 1L","7501234567891",NULL,"2","32.00","46.40","1.29","20","3","2025-11-09 17:29:40","2025-11-09 17:29:40");
INSERT INTO `productos` VALUES("5","Detergente 500g","7501234567892",NULL,"3","24.00","34.80","0.97","24","3","2025-11-09 17:29:40","2025-11-14 20:14:12");

-- Table: users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','cashier') DEFAULT 'cashier',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: users
INSERT INTO `users` VALUES("1","Administrador","admin@ninemarket.com","$2y$10$vpPy8AB8VC/t8vpu9rKNjuq1jRqGNVAQTOFgY1brFuGtvfcL1YMgS","admin","2025-11-09 17:29:40","2025-11-14 20:19:18");

-- Table: ventas
DROP TABLE IF EXISTS `ventas`;
CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `factura_original_id` int(11) DEFAULT NULL,
  `tipo_factura` enum('original','refacturacion','nota_ajuste') DEFAULT 'original',
  `motivo_refacturacion` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `total_bs` decimal(12,2) NOT NULL,
  `total_usd` decimal(12,2) NOT NULL,
  `metodo_pago` enum('efectivo','credito','mixto') NOT NULL,
  `tasa_cambio` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `factura_original_id` (`factura_original_id`),
  CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `ventas_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `ventas_ibfk_3` FOREIGN KEY (`factura_original_id`) REFERENCES `ventas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: ventas
INSERT INTO `ventas` VALUES("1",NULL,"original",NULL,"1","1","69.60","1.93","efectivo","36.00","2025-11-14 20:14:12","2025-11-14 20:14:12");

SET FOREIGN_KEY_CHECKS=1;
