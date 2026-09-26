<?php

class RadiusRest {

    // show Description
    function description()
    {
        return [
            'title' => 'Radius Rest API',
            'description' => 'This devices will handle Radius Connection using Rest API',
            'author' => 'Mustafizur Rahman Mahin (Arivo maintainer)',
            'url' => [
                'Arivo RADIUS Guide' => 'https://github.com/mahin-wpdev/panel/blob/next-release/docs/new-freeradius-deploy-ubuntu24.md',
                'Support' => 'https://github.com/mahin-wpdev/panel/issues',
                'Maintainer' => 'https://github.com/mahin-wpdev'
            ]
        ];
    }

    // Add Customer to Mikrotik/Device
    function add_customer($customer, $plan)
    {
    }

	function sync_customer($customer, $plan)
    {
        $this->add_customer($customer, $plan);
    }

    // Remove Customer to Mikrotik/Device
    function remove_customer($customer, $plan)
    {
        // set zero data usage
        if ($plan['typebp'] == "Limited" && ($plan['limit_type'] == "Data_Limit" || $plan['limit_type'] == "Both_Limit")) {
            $cs = ORM::for_table("rad_acct")->where('username', $customer['username'])->findMany();
            foreach ($cs as $c) {
                $c->acctOutputOctets = 0;
                $c->acctInputOctets = 0;
                $c->save();
            }
        }
    }

    // customer change username
    public function change_username($plan, $from, $to)
    {
    }

    // Add Plan to Mikrotik/Device
    function add_plan($plan)
    {
    }

    // Update Plan to Mikrotik/Device
    function update_plan($old_name, $plan)
    {
    }

    // Remove Plan from Mikrotik/Device
    function remove_plan($plan)
    {
    }

    // check if customer is online
    function online_customer($customer, $router_name)
    {
    }

    // make customer online
    function connect_customer($customer, $ip, $mac_address, $router_name)
    {
    }

    // make customer disconnect
    function disconnect_customer($customer, $router_name)
    {
    }

}
