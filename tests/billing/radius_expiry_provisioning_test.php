<?php

require_once __DIR__ . '/../../system/devices/Radius.php';

class RadiusExpiryCapture extends Radius
{
    public $captured = null;

    public function customerAddPlan($customer, $plan, $expired = '')
    {
        $this->captured = $expired;
    }
}

$device = new RadiusExpiryCapture();
$expected = '2027-01-12 12:00:00';
$device->add_customer(['username' => 'test'], ['id' => 4], $expected);
if ($device->captured !== $expected) {
    fwrite(STDERR, "RADIUS must receive the committed package expiry.\n");
    exit(1);
}

echo "RADIUS recharge expiry propagation passed.\n";
