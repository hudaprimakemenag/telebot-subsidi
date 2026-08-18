<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Api;

class SubsidiController extends Controller
{
    public function index()
    {
        $telegram = new Api('8126177348:AAG36DlX_WwTSZF7wIMfvVR8ytxeMquXJSg');
        $idMessage = $telegram->getUpdates()[0]->update_id + 1;

        $message = $telegram->getUpdates()[0]->message->text == '1'
            ? 'anda menjawab 1'
            : 'bukan satu';

        $response = $telegram->sendMessage(['chat_id' => '487930753', 'text' => $message]);
        $messageId = $response->getMessageId();

        $response = Http::get(
            'https://api.telegram.org/bot8126177348:AAG36DlX_WwTSZF7wIMfvVR8ytxeMquXJSg/getUpdates?offset=' . $idMessage
        );

        dd($messageId, $idMessage, $telegram->getUpdates());
    }

    public function create()
    {
        if (!$this->cekKondisi()) {
            return;
        }

        $data = DB::table('penerima')
            ->where('is_send', 'false')
            ->orderBy('id', 'asc')
            ->first();

        if (!$data) {
            return response()->json(['status' => false, 'message' => 'Tidak ada data']);
        }

        $nik = $data->nik;
        $cekNik = Http::withToken($this->getToken())
            ->get('https://api-map.my-pertamina.id/general/customer-service/v1/verify-nik?nationalityId=' . $nik);


        if ($cekNik->status() != 200) {
            $this->sendTelegram('NIK tidak ditemukan : ' . $nik . ' ' . $cekNik->body());
            return response()->json(['status' => true, 'message' => 'Selesai']);
        }

        $result = json_decode($cekNik->body());
        sleep(2);

        if (!$result->success) {
            $this->sendTelegram('NIK Gagal : ' . $nik);
            return response()->json(['status' => true, 'message' => 'Selesai']);
        }

        if (count($result->data->customerTypes) != 1) {
            $this->markFailed($data->id);
            $this->sendTelegram('NIK multi kategori : ' . $nik);
            return response()->json(['status' => true, 'message' => 'Selesai']);
        }

        if ($result->data->customerTypes[0]->name != 'Rumah Tangga') {
            $this->markFailed($data->id);
            $this->sendTelegram('NIK bukan rumah tangga : ' . $nik);
            return response()->json(['status' => true, 'message' => 'Selesai']);
        }

        // Jika belum setuju terms, lakukan agreement terlebih dahulu
        if (!$result->data->isAgreedTerms) {
            $agreementOk = $this->postDanPutAgreement($nik);
            if (!$agreementOk) {
                return response()->json(['status' => true, 'message' => 'Selesai']);
            }
        }

        // Kirim transaksi
        $this->postTransaksi($data, $nik, $result);

        return response()->json(['status' => true, 'message' => 'Selesai']);
    }

    // --- Private Helpers ------------------------------------------------------

    /**
     * POST terms-consent lalu PUT registration.
     * Mengembalikan true jika keduanya sukses.
     */
    private function postDanPutAgreement(string $nik): bool
    {
        $postAgreement = Http::withToken($this->getToken())
            ->asMultipart()
            ->post('https://api-map.my-pertamina.id/general/customer-service/v1/terms-consent', [
                ['name' => 'historyIdAgreement', 'contents' => 20],
                ['name' => 'historyIdTerm', 'contents' => 19],
                ['name' => 'customerType', 'contents' => 'Rumah Tangga'],
                ['name' => 'nationalityId', 'contents' => $nik],
            ]);

        if ($postAgreement->status() != 200) {
            return false;
        }

        $putAgreement = Http::withToken($this->getToken())
            ->asMultipart()
            ->put('https://api-map.my-pertamina.id/customers/v3/registration/' . $nik . '/Rumah%20Tangga', [
                ['name' => 'pob', 'contents' => getKotaLahirDariNik($nik)],
                ['name' => 'dob', 'contents' => getTanggalLahirDariNik($nik)],
            ]);

        return $putAgreement->status() == 200;
    }

    /**
     * POST transaksi dan update status penerima.
     */
    private function postTransaksi(object $data, string $nik, object $result): void
    {
        $customerType = $result->data->customerTypes[0];

        $post = Http::withToken($this->getToken())
            ->asMultipart()
            ->post('https://api-map.my-pertamina.id/general/v3/transactions', [
                ['name' => 'quantity', 'contents' => 1],
                ['name' => 'token', 'contents' => $result->data->token],
                ['name' => 'nationalityId', 'contents' => $nik],
                ['name' => 'familyIdEncrypted', 'contents' => $result->data->familyIdEncrypted],
                ['name' => 'category', 'contents' => $customerType->name],
                ['name' => 'sourceTypeId', 'contents' => $customerType->sourceTypeId],
                ['name' => 'name', 'contents' => $result->data->name],
                ['name' => 'channelInject', 'contents' => $result->data->channelInject],
                ['name' => 'coordinate', 'contents' => '-,-'],
            ]);

        if ($post->status() != 200) {
            $this->markFailed($data->id);
            $this->sendTelegram('NIK gagal insert : ' . $nik . ' ' . $post->body());
            return;
        }

        $this->markSuccess($data->id);
        $total = $this->incrementPosition();
        $this->sendTelegram('NIK berhasil insert: ' . $nik . ' Total ' . $total);
    }

    /** Tandai penerima sebagai gagal. */
    private function markFailed(int $id): void
    {
        DB::table('penerima')->where('id', $id)->update(['is_send' => 'failed']);
    }

    /** Tandai penerima sebagai berhasil. */
    private function markSuccess(int $id): void
    {
        DB::table('penerima')->where('id', $id)->update(['is_send' => 'true']);
    }

    /** Increment posisi dan kembalikan nilai terbaru. */
    private function incrementPosition(): int
    {
        $current = (int) DB::table('setting')->where('type', 'position')->value('value');
        DB::table('setting')->where('type', 'position')->update(['value' => $current + 1]);
        return $current + 1;
    }

    // --- Lain-lain ------------------------------------------------------------

    private function listNik(int $param): array
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
            ],
        ];

        return $array[$param];
    }

    private function getToken(): string
    {
        return 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMTAwZDdkZC00ODJhLTQ4YzUtYmY0Ny1jY2E5OGU4NDNmZDIiLCJpYXQiOjE3ODcwNTc2NjcsImV4cCI6MTc4NzA1ODU2NywiYXVkIjoibWVyY2hhbnQiLCJpc3MiOiJtYXAtbGl0ZSJ9.SQ_T2rUWj66ccbU_hzonwE8ZWx6mYPUOqNsnc2qonZ8YmfCWYhlCuW9hV2g_NsKEvj0MGZ4aeqccayCEGbvw5F94FC_3PnHY-G0Uxk3pLUnuNNvvWVAq_U0s180kEw1jtse4sDGwDoNcG4k54hcDd8ASZvlDrZC1QdL51jJyhDwTGPluofMVD8ST0mUzy2mS4Uj7pqxrkCFWT4knI4ab45AXAawUICLaCH_pt0pkjbmFs2RXkSqRzPBv-V-pgDVSVUIXx0mIent3hQDsnowDgCKP6Bg86yCcis82Qb1qFaOKHj7r8E11VW6MrOPtG2Cpfe5Kzj3CPzC-52yRPJi1jQ7YUK9QeQ58faPZhHwsvlZ8KLgH3B-NPjuLn-ISBA4IoCb-zsne9D2TgAFTOEmIbdpf3nSBRE5ORvdeyFhmxcUZjF6Rr0YuFePcxveP9pW00HtVc5Eh4kXEoVopoMiyiKKSywPQS2Cds5FvHLW8aFVlPFV-cS4CxHSIAS8aHqKRUKW8iRLM9itWomy7zhQEVhoY9bH7tLeXvSOwcccual1kmuOXQB2Zv8dMjfHoxlhW5_HWxN63kHnGHoqokCiPLmVyuEbhJrVEgW3uT59joVGyMlRoGt790bRTRqyYKkTyUSivF-A0i7EQvfPpG5QoKnuoRQGSftl3UysfAOpc_OM';
    }

    private function sendTelegram(string $message): void
    {
        $telegram = new Api('8126177348:AAG36DlX_WwTSZF7wIMfvVR8ytxeMquXJSg');
        $telegram->sendMessage(['chat_id' => '487930753', 'text' => $message]);
    }

    private function cekKondisi(): bool
    {
        $total = (int) DB::table('setting')->where('type', 'total')->value('value');
        $position = (int) DB::table('setting')->where('type', 'position')->value('value');

        return $total > $position;
    }
}
