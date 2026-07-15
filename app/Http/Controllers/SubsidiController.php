<?php

namespace App\Http\Controllers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\DB;

class SubsidiController extends Controller
{
    public function index()
    {
        $telegram = new Api('8126177348:AAG36DlX_WwTSZF7wIMfvVR8ytxeMquXJSg');
        $response = $telegram->getMe();

        $idMessage = $telegram->getUpdates()[0]->update_id + 1;

        $message = "bukan satu";

        if ($telegram->getUpdates()[0]->message->text == "1") {
            $message = "anda menjawab 1";
        }

        $response = $telegram->sendMessage([
            'chat_id' => '487930753',
            'text' => $message
        ]);

        $messageId = $response->getMessageId();

        $response = Http::get('https://api.telegram.org/bot8126177348:AAG36DlX_WwTSZF7wIMfvVR8ytxeMquXJSg/getUpdates?offset=' . $idMessage);

        dd($messageId, $idMessage, $telegram->getUpdates());
    }

    public function create()
    {
        $source = $this->listNik(0);
        if ($this->cekKondisi()) {
            $data = DB::table('penerima')
                ->where('is_send', "false")
                ->orderBy("id", "asc")
                ->first();

            $nik = $data->nik;
            if ($data) {
                $cekNik = Http::withToken($this->getToken())
                    ->get('https://api-map.my-pertamina.id/customers/v2/verify-nik?nationalityId=' . $nik);

                if ($cekNik->status() == 200) {

                    $result = json_decode($cekNik->body());
                    sleep(2);
                    if (($result->success)) {
                        if (count($result->data->customerTypes) == 1) {
                            if ($result->data->customerTypes[0]->name == "Rumah Tangga") {
                                // $rawInsert = [
                                //     "products" => [
                                //         [
                                //             "productId" => "c74228ca-2083-47fd-87ab-4790a9cea2de",
                                //             "quantity" => 1
                                //         ]
                                //     ],
                                //     "token" => $result->data->token,
                                //     "subsidi" => [
                                //         "nik" => $nik,
                                //         "familyIdEncrypted" => $result->data->familyIdEncrypted,
                                //         "category" => $result->data->customerTypes[0]->name,
                                //         "sourceTypeId" => $result->data->customerTypes[0]->sourceTypeId,
                                //         "nama" => $result->data->name,
                                //         "channelInject" => $result->data->channelInject,
                                //     ]
                                // ];

                                // $post =  Http::withToken($this->getToken())
                                //     ->post('https://api-map.my-pertamina.id/general/v2/transactions', $rawInsert);

                                $rawInsert = [
                                    "quantity" => 1,
                                    "token" => $result->data->token,
                                    "nationalityId" => $nik,
                                    "familyIdEncrypted" => $result->data->familyIdEncrypted,
                                    "category" => $result->data->customerTypes[0]->name,
                                    "sourceTypeId" => $result->data->customerTypes[0]->sourceTypeId,
                                    "name" => $result->data->name,
                                    "channelInject" => $result->data->channelInject,
                                    "coordinate" => "-,-",
                                ];


                                $post = Http::withToken($this->getToken())
                                    ->asMultipart() // multipart/form-data
                                    ->post('https://api-map.my-pertamina.id/general/v3/transactions', [
                                        ['name' => 'quantity', 'contents' => 1],
                                        [
                                            'name' => 'token',
                                            'contents' => $result->data->token,
                                        ],
                                        [
                                            'name' => 'nationalityId',
                                            'contents' => $nik,
                                        ],
                                        [
                                            'name' => 'familyIdEncrypted',
                                            'contents' => $result->data->familyIdEncrypted,
                                        ],
                                        [
                                            'name' => 'category',
                                            'contents' => $result->data->customerTypes[0]->name,
                                        ],
                                        [
                                            'name' => 'sourceTypeId',
                                            'contents' => $result->data->customerTypes[0]->sourceTypeId,
                                        ],
                                        [
                                            'name' => 'name',
                                            'contents' => $result->data->name,
                                        ],
                                        [
                                            'name' => 'channelInject',
                                            'contents' => $result->data->channelInject,
                                        ],
                                        [
                                            'name' => 'coordinate',
                                            'contents' => '-,-',
                                        ],
                                    ]);

                                if ($post->status() != 200) {
                                    DB::table('penerima')
                                        ->where('id', $data->id)
                                        ->update(['is_send' => "failed"]);

                                    $this->sendTelegram('NIK gagal insert : ' . $nik . ' ' . $post->body());
                                } else {
                                    DB::table('penerima')
                                        ->where('id', $data->id)
                                        ->update(['is_send' => "true"]);

                                    $getPosition = DB::table('setting')
                                        ->where('type', "position")
                                        ->first();

                                    DB::table('setting')
                                        ->where('type', "position")
                                        ->update(['value' => (int) $getPosition->value + 1]);
                                    $this->sendTelegram('NIK berhasil insert: ' . $nik . ' Total' . (int) $getPosition->value + 1);
                                }
                            } else {
                                DB::table('penerima')
                                    ->where('id', $data->id)
                                    ->update(['is_send' => "failed"]);

                                $this->sendTelegram('NIK bukan rumah tangga : ' . $nik);
                            }
                        } else {
                            DB::table('penerima')
                                ->where('id', $data->id)
                                ->update(['is_send' => "failed"]);

                            $this->sendTelegram('NIK multi kategori : ' . $nik);
                        }
                    } else {
                        $this->sendTelegram('NIK Gagal : ' . $nik);
                    }
                } else {
                    $this->sendTelegram('NIK tidak ditemukan : ' . $nik . " " . $cekNik->body());
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Selesai',
            ]);
        }
    }

    private function listNik($param)
    {
        $array = [
            [
                '3401067108740021',
                '3401066806580001',
                '3401066501750001',
                '3401025011650001',
                '3401066104530001',
                '3401064210570001',
                '3401061706650001',
                '3401066410720001',
                '3401101006790001',
                '3401106508790001',
                '3401066602640001',
                '3401063112700162',
                '3401062007790061',
                '3401065309630001',
                '3401105409800002',
                '3401064409960001',
                '3401064409960001',
                '3401060608830001',
                '3401060306950001',
                '3401061705970021',
                '3401060711730021',
                '3401060211970042',
                '3401063005950001',
                '3310082103770001',
                '3401026212790021',
                '3401045808830003',
                '3401064409960001',
                '3401022111940001',
                '3401060306950001',
                '3401063005950001',
                '3401010412740001',
                '3401122503000001',
                '3404066507850001',
                '3401016904980002',
                '3401036212920003',
                '3471145403880002',
                '3401056106010001',
                '3401055912990002',
                '3401056008000001',
                '3401096308980002',
                '3401064103980041',
                '3471040307040001',
                '3471040306780001',
                '3471040306630001',
                '3471040306030001',
            ]
        ];

        return $array[$param];
    }

    private function getToken()
    {
        $token = "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMTAwZDdkZC00ODJhLTQ4YzUtYmY0Ny1jY2E5OGU4NDNmZDIiLCJpYXQiOjE3ODMzMDM5NDgsImV4cCI6MTc4MzMwNDg0OCwiYXVkIjoibWVyY2hhbnQiLCJpc3MiOiJtYXAtbGl0ZSJ9.BCXVnQfpa6xfHwqyuFQqDgk_eKdIVL0jfhivwbk-DimrvABdSxeGUjNzRYb4WWBJNQavh4plgozRKO1rsqvT0hCWXz1dFtgRpqsiXPzr3Lun-FvrBUaHSeHCIhv9fCXxK_XlEI99V3jbyhNbpIfrc3gqYEY_BisVP59CkmNO_b4VNnZi7stjfA3bEpaJ8mJ0baNWRFFPw91ICVQfV2itsUm_r7lPIlL5TLcC7jh4gfqGCHvBoA5voYTkXppBO1xcOAtQ9RPEla0rnWmvoZi6wZ7fJeudmxCRXkjVh8Q8mMQxCCYVr911_AHazVqIBCXjznjFfk_Qk3PVbK5dmYj1Ttuo2Z9eD7_52WyTXdCmphXp2E9GodudueRC9FBGmfrfNZZmxa_62-xZbONR48out5k12XEPBOr0POnXShPgxKhjiX4aPoDbCi5TjL6YEi_U3cISQhqzhxTyxW7mZlzEY_kA7CapCKJCbR-kq9-MQZOEY_IPOpUJmnZto1a21hxNS9QHfPmCf8m9bsNJc0eYdfKqWCbDxc1Ucyq_Xy9501xLa2eRzfHuETB-bSLdu2Kj_UXCIgeiWPFTbsZznzEiqAGdg4Se5shpgxMTRyxYnGcsRwFCsllQoFDTU-Qga6ae00boAWlMMUd0jeFU5S6_NtQWx39JtzKEnNr-jwCxB0Q";
        return $token;
    }

    private function sendTelegram($message)
    {
        $telegram = new Api('8126177348:AAG36DlX_WwTSZF7wIMfvVR8ytxeMquXJSg');


        $response = $telegram->sendMessage([
            'chat_id' => '487930753',
            'text' => $message
        ]);
    }

    private function cekKondisi()
    {
        $getTotal = DB::table('setting')
            ->where('type', "total")
            ->first();

        $getPosition = DB::table('setting')
            ->where('type', "position")
            ->first();

        if ($getTotal->value > $getPosition->value) {
            return true;
        }

        return false;
    }
}
