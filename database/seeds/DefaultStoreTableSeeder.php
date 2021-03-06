<?php
/**
 * Created by PhpStorm.
 * User: Sugito
 * Date: 9/7/2016
 * Time: 11:18 AM
 */

use Illuminate\Database\Seeder;
use App\Model\Store;

class DefaultStoreTableSeeder extends Seeder
{
    public function run()
    {
        $store = [
            [
                'name'          => 'Toko Baru',
                'address'       => 'Jln Raya Utara No 67 Wangon',
                'phone_num'     => '0281 - 531270',
                'fax_num'       => '0281 - 583358',
                'tax_id'        => '0000000000',
                'status'        => 'STATUS.ACTIVE',
                'is_default'    => 'YESNOSELECT.YES',
                'remarks'       => ''
            ]
        ];
        foreach ($store as $key => $value) {
            Store::create($value);
        }
    }
}