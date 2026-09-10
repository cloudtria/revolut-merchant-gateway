<?php

final class RevolutToken
{
    public static function encode($customerId, $paymentMethodId)
    {
        return 'rv1:' . $customerId . ':' . $paymentMethodId;
    }

    public static function decode($value)
    {
        $parts = explode(':', (string) $value, 3);
        if (count($parts) !== 3 || $parts[0] !== 'rv1' || !$parts[1] || !$parts[2]) {
            throw new InvalidArgumentException('The saved Revolut payment method is invalid.');
        }
        return ['customer_id' => $parts[1], 'payment_method_id' => $parts[2]];
    }
}
