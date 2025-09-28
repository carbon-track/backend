<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->load();
$container = new DI\Container();
$init = require __DIR__ . '/src/dependencies.php';
$init($container);
/** @var CarbonTrack\Services\EmailService $email */
$email = $container->get(CarbonTrack\Services\EmailService::class);
$ref = new ReflectionClass($email);
$prop = $ref->getProperty('forceSimulation');
$prop->setAccessible(true);
$force = $prop->getValue($email) ? 'true' : 'false';
$propMailer = $ref->getProperty('mailer');
$propMailer->setAccessible(true);
$mailer = $propMailer->getValue($email);
$status = $mailer ? get_class($mailer) : 'NULL';
$fromAddressProp = $ref->getProperty('fromAddress');
$fromAddressProp->setAccessible(true);
$fromNameProp = $ref->getProperty('fromName');
$fromNameProp->setAccessible(true);
$host = (new ReflectionProperty(CarbonTrack\Services\EmailService::class, 'config'))->setAccessible(true);configProp = ref->getProperty('config');
