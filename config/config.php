<?php

return [
    'shop_id' => [
        'value' => config('integrations.yookassa.shop_id'),
        'type' => 'text',
        'required' => true,
        'description' => 'Shop ID'
    ],
    'secret_token' => [
        'value' => config('integrations.yookassa.secret_token'),
        'type' => 'text',
        'required' => true,
        'description' => 'Секретный ключ'
    ]
];
