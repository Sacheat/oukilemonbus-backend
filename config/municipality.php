<?php

return [
    'name' => env('MUNICIPALITY_NAME', 'Dinant'),

    'localities' => array_values(array_filter(array_map(
        'trim',
        explode(
            ',',
            env(
                'MUNICIPALITY_LOCALITIES',
                'Dinant,Anseremme,Bouvignes-sur-Meuse,Drehance,Falmagne,Falmignoul,Furfooz,Lisogne,Sorinnes,Thynes'
            )
        )
    ))),
];
