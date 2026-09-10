<?php

final class RevolutToken
{
    public static function encode($customerId, $paymentMethodId, $type = 'card')
    {
        $type = strtolower((string) $type);
        if (!in_array($type, ['card', 'revolut_pay'], true)) {
            throw new InvalidArgumentException('The Revolut payment method type cannot be saved.');
        }
        return 'rv2:' . $type . ':' . $customerId . ':' . $paymentMethodId;
    }

    public static function decode($value)
    {
        $parts = explode(':', (string) $value);
        if (count($parts) === 3 && $parts[0] === 'rv1' && $parts[1] && $parts[2]) {
            return ['type' => 'card', 'customer_id' => $parts[1], 'payment_method_id' => $parts[2]];
        }
        if (count($parts) === 4 && $parts[0] === 'rv2' && in_array($parts[1], ['card', 'revolut_pay'], true) && $parts[2] && $parts[3]) {
            return ['type' => $parts[1], 'customer_id' => $parts[2], 'payment_method_id' => $parts[3]];
        }
        throw new InvalidArgumentException('The saved Revolut payment method is invalid.');
    }
}
