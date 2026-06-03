<?php
require 'utils/helpers.php';
require 'config/db.php';
require 'services/RoomService.php';
$service = new RoomService($conn);
print_r($service->getBuildingSummary());
