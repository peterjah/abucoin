<?php
function handle_offers($stack, $offers, $side, $stackSize)
{
    $stack = $stack[$side];
    foreach ($offers as $new_offer) {
        $new_price = floatval($new_offer[0]);
        $new_vol = floatval($new_offer[1]);
        foreach ($stack as $key => $offer) {
            if (isBetter($new_price, floatval($offer[0]), $side) && $new_vol > 0) {
                array_splice($stack, $key, 0, [$new_offer]);
                break;
            }
            if ($new_price == floatval($offer[0])) {
                if ($new_vol == 0) {
                    unset($stack[$key]);//ok
                } else {
                    $stack[$key] = $new_offer;
                }
                break;
            }
            if ($key < ($stackSize -1) && !isset($stack[$key+1]) && $new_vol > 0) {
                $stack[$key+1] = $new_offer;
                break;
            }
        }
        $stack = array_values($stack);
        $stack = array_slice($stack, 0, $stackSize);
    }
    return $stack;
}


function isBetter($price, $ref, $side)
{
    if (isAsk($side)) {
        return $price < $ref;
    } else {
        return $price > $ref;
    }
}

function isAsk($side)
{
    return $side === 'asks';
}

function parseBook($file) {
    $fd = fopen($file, "r");
    flock($fd, LOCK_SH, $w);
    $res = json_decode(file_get_contents($file), true);
    flock($fd, LOCK_UN);
    fclose($fd);
    return $res;
}
