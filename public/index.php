<?php
require __DIR__ . '/../vendor/autoload.php';

// connection db
$db_url = getenv('DB_HOST');
$db_user = getenv('DB_UNAME');
$db_pwd = getenv('DB_PWD');
$db_name = getenv('DB_NAME');

$conn = mysqli_connect($db_url, $db_user, $db_pwd, $db_name);

if (!$conn){
    die("Error when initializing connection with the database");
}
// connection db
 
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
 
use \LINE\LINEBot;
use \LINE\LINEBot\HTTPClient\CurlHTTPClient;
use \LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use \LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use \LINE\LINEBot\SignatureValidator as SignatureValidator;

// fuzzywuzzy
use FuzzyWuzzy\Fuzz;
use FuzzyWuzzy\Process;
 
$pass_signature = false;
 
// set LINE channel_access_token and channel_secret
$channel_access_token = getenv('CHANNEL_ACCESS_TOKEN');
$channel_secret = getenv('CHANNEL_SECRET');
 
// inisiasi objek bot
$httpClient = new CurlHTTPClient($channel_access_token);
$bot = new LINEBot($httpClient, ['channelSecret' => $channel_secret]);
 
$app = AppFactory::create();
$app->setBasePath("/public");
 
$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello World!");
    return $response;
});
 
// buat route untuk webhook
$app->post('/webhook', function (Request $request, Response $response) use ($channel_secret, $bot, $httpClient, $pass_signature, $conn) {
    // get request body and line signature header
    $body = $request->getBody();
    $signature = $request->getHeaderLine('HTTP_X_LINE_SIGNATURE');
 
    // log body and signature
    file_put_contents('php://stderr', 'Body: ' . $body);
 
    if ($pass_signature === false) {
        // is LINE_SIGNATURE exists in request header?
        if (empty($signature)) {
            return $response->withStatus(400, 'Signature not set');
        }
 
        // is this request comes from LINE?
        if (!SignatureValidator::validateSignature($body, $channel_secret, $signature)) {
            return $response->withStatus(400, 'Invalid signature');
        }
    }
    
    $data = json_decode($body, true);
    if(is_array($data['events'])){
        foreach ($data['events'] as $event){
            // dari setiap reaction kita cek
            // 1. user add as friend aka follow
            if ($event['type'] == "follow"){
                // welcome msg
                $welcome = new TextMessageBuilder("Halo! Terima kasih telah menambahkan bot ini menjadi teman kamu. Sesuai namanya, kamu dapat menggunakan bot ini untuk menuliskan tugas-tugas kamu.");

                // help mgs
                $help = new TextMessageBuilder("Untuk menggunakan bot ini, kirimkan .help (dengan titik). Bot akan segera menjawab kamu dengan pilihan-pilihan perintah yang dapat dipahami oleh bot.");

                $multi_msg = new MultiMessageBuilder();
                $multi_msg->add($welcome);
                $multi_msg->add($help);

                $result = $bot->replyMessage($event['replyToken'], $multi_msg);
     
                $response->getBody()->write(json_encode($result->getJSONDecodedBody()));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus($result->getHTTPStatus());
            }

            // 2. ditambahkan dalam grup
            if ($event['type'] == "join"){
                // welcome msg
                $welcome = new TextMessageBuilder("Halo! Terima kasih telah menambahkan bot ini ke dalam grup atau multiple chat kamu. Sesuai namanya, kamu dapat menggunakan bot ini untuk menuliskan tugas-tugas kamu.");

                // help mgs
                $help = new TextMessageBuilder("Untuk menggunakan bot ini, kirimkan .help (dengan titik). Bot akan segera menjawab kamu dengan pilihan-pilihan perintah yang dapat dipahami oleh bot.");

                $multi_msg = new MultiMessageBuilder();
                $multi_msg->add($welcome);
                $multi_msg->add($help);

                $result = $bot->replyMessage($event['replyToken'], $multi_msg);
     
                $response->getBody()->write(json_encode($result->getJSONDecodedBody()));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus($result->getHTTPStatus());
            }

            // 3. menjawab perintah
            if ($event['type'] == 'message'){
                // typenya text?
                if($event['message']['type'] == 'text'){

                    # ambil sender
                    if ($event['source']['type'] == "user"){
                        $sumber = $event['source']['userId'];
                    } else if ($event['source']['type'] == "room"){
                        $sumber = $event['source']['roomId'];
                    } else if ($event['source']['type'] == "group"){
                        $sumber = $event['source']['groupId'];
                    }

                    # cek awalna
                    if (substr($event['message']['text'], 0, 1) == "."){
                        # minta bantuan?
                        if ($event['message']['text'] == ".help"){
                            $bantuan = new TextMessageBuilder("Untuk menggunakan bot ini, silakan gunakan beberapa perintah di bawah ini ya.

.help, untuk menampilkan pesan ini.
.lihat, untuk menampilkan semua tugas.
.tambah <tugas>, untuk menambahkan tugas.
.hapus <id>, untuk menghapus tugas.");

                            $multi_msg = new MultiMessageBuilder();
                            $multi_msg->add($bantuan);

                            $result = $bot->replyMessage($event['replyToken'], $multi_msg);

                            $response->getBody()->write(json_encode($result->getJSONDecodedBody()));
                            return $response
                                ->withHeader('Content-Type', 'application/json')
                                ->withStatus($result->getHTTPStatus());
                        }
                        
                        # lihat tugas?
                        else if ($event['message']['text'] == ".lihat"){
                            $tugas = [];

                            // select di database
                            $query = "SELECT id, detail FROM tugas WHERE room_id = '$sumber'";
                            $result = mysqli_query($conn, $query);

                            if (mysqli_num_rows($result) > 0){
                                while($row = mysqli_fetch_assoc($result)) {
                                    $tugas[$row['id']] = $row["detail"];
                                }
                            }

                            if (empty($tugas)){
                                $teks = "Tidak ada tugas yang pending.";
                                $result = $bot->replyText($event['replyToken'], $teks);
                            } else {
                                $counter = count($tugas);
                                $flexTemplate = file_get_contents("pending.json");

                                $flexTemplate = json_decode($flexTemplate, true);

                                $flexTemplate['header']['contents'][1]['text'] = "Ada $counter tugas yang pending";

                                foreach ($tugas as $id => $detail) {
                                    $flexTemplate['body']['contents'][0]['contents'][] = [
                                            "type" => "text",
                                            "text" => $id . ". " . $detail,
                                            "color" => "#8C8C8C",
                                            "size" => "sm",
                                            "wrap" => true
                                    ];
                                }

                                $flexTemplate = json_encode($flexTemplate);
                                error_log($flexTemplate);

                                $result = $httpClient->post(LINEBot::DEFAULT_ENDPOINT_BASE . '/v2/bot/message/reply', [
                                    'replyToken' => $event['replyToken'],
                                    'messages'   => [
                                        [
                                            'type'     => 'flex',
                                            'altText'  => 'Lihat daftar tugas',
                                            'contents' => json_decode($flexTemplate)
                                        ]
                                    ],
                                ]);
                            }

                            $response->getBody()->write(json_encode($result->getJSONDecodedBody()));
                            return $response
                                ->withHeader('Content-Type', 'application/json')
                                ->withStatus($result->getHTTPStatus());
                        }

                        # hapus tugas
                        else if (substr($event['message']['text'], 0, 6) == ".hapus"){
                            $id = substr($event['message']['text'], 7);

                            $query = "SELECT id FROM tugas WHERE room_id = '$sumber' AND id = '$id'";
                            $result = mysqli_query($conn, $query);

                            if (mysqli_num_rows($result) > 0){
                                $query = "DELETE FROM tugas WHERE room_id = '$sumber' AND id = '$id'";

                                if (mysqli_query($conn, $query)){
                                    $teks = "Tugas berhasil terhapus!";
                                    $result = $bot->replyText($event['replyToken'], $teks);
                                } else {
                                    $teks = "Tugas tidak dapat dihapus. Apa nomor ID yang Anda masukkan sudah benar?";
                                    $result = $bot->replyText($event['replyToken'], $teks);
                                }
                            } else {
                                $teks = "Tugas tidak terhapus. Saya tidak dapat menemukan tugas dengan id: $id.";
                                $result = $bot->replyText($event['replyToken'], $teks);
                            }

                            $response->getBody()->write(json_encode($result->getJSONDecodedBody()));
                            return $response
                                ->withHeader('Content-Type', 'application/json')
                                ->withStatus($result->getHTTPStatus());
                        }

                        # tambah tugas
                        else if (substr($event['message']['text'], 0, 7) == ".tambah"){
                            $detail = substr($event['message']['text'], 8);

                            $query = "INSERT INTO tugas(room_id, detail) VALUES ('$sumber', '$detail')";

                            if (mysqli_query($conn, $query)){
                                $teks = "Tugas berhasil ditambahkan!";
                                $result = $bot->replyText($event['replyToken'], $teks);
                            } else {
                                $teks = "Hmm ... Tugas tidak dapat ditambahkan.";
                                $result = $bot->replyText($event['replyToken'], $teks);
                            }

                            $response->getBody()->write(json_encode($result->getJSONDecodedBody()));
                            return $response
                                ->withHeader('Content-Type', 'application/json')
                                ->withStatus($result->getHTTPStatus());
                        } else {
                            # tidak di atas
                            # fuzzywuzzy to the action
                            $fuzz = new Fuzz();
                            $process = new Process($fuzz);

                            # max dan kemungkinan
                            $max = -1;
                            # pilihan
                            $pilihan = ['.help', '.tambah', '.hapus', '.lihat'];

                            foreach ($pilihan as $p) {
                                $rasio = $fuzz->ratio($event['message']['text'], $p);

                                if ($rasio > $max){
                                    $max = $rasio;
                                    $kemungkinan = $p;
                                }
                            }

                            # send back
                            $teks = "Maaf, tapi saya tidak mengerti. Mungkin maksud Anda {$kemungkinan}?";
                            $result = $bot->replyText($event['replyToken'], $teks);

                            $response->getBody()->write(json_encode($result->getJSONDecodedBody()));
                            return $response
                                ->withHeader('Content-Type', 'application/json')
                                ->withStatus($result->getHTTPStatus());
                        }
                    }
                }
            }
        }
    }
 
});
$app->run();
 
