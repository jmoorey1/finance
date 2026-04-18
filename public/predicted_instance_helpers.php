<?php

if (!function_exists('predicted_instance_defaults')) {
    function predicted_instance_defaults(): array
    {
        return [
            'id' => '',
            'scheduled_date' => '',
            'description' => '',
            'from_account_id' => '',
            'to_account_id' => '',
            'category_id' => '',
            'amount' => '',
        ];
    }
}
