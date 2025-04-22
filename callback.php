<?php
error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 0);

$config = parse_ini_file('config.ini', true);
$cached_token = null;
$token_expires = 0;

// 封装的 curl POST 请求函数
function curl_post($url, $header, $data) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => FALSE,
        CURLOPT_SSL_VERIFYHOST => FALSE,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => $header,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 30
    ]);
    $output = curl_exec($curl);
    curl_close($curl);
    return $output;
}

// 封装的 curl PUT 请求函数
function curl_put($url, $header, $data) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => FALSE,
        CURLOPT_SSL_VERIFYHOST => FALSE,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_HTTPHEADER => array_map('trim', $header),
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HEADER => true
    ]);
    $output = curl_exec($curl);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $body = substr($output, $header_size);
    curl_close($curl);
    return $body;
}

// 封装的 curl GET 请求函数（用于下载文件）
function curl_get_contents($url) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => FALSE,
        CURLOPT_SSL_VERIFYHOST => FALSE,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 30
    ]);
    $output = curl_exec($curl);
    curl_close($curl);
    return $output;
}

// 添加新的 curl GET 请求函数
function curl_get($url, $header) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => FALSE,
        CURLOPT_SSL_VERIFYHOST => FALSE,
        CURLOPT_HTTPHEADER => array_map('trim', $header),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    $output = curl_exec($curl);
    curl_close($curl);
    return $output;
}

// 检查 file_url 参数
if (!isset($_GET['file_url'])) {
    http_response_code(400);
    echo json_encode(["error" => 8, "message" => "Missing 'file_url' parameter"]);
    exit;
}
$input_file_url = $_GET['file_url'];
$path = parse_url($input_file_url, PHP_URL_PATH);
if ($path === false || empty($path)) {
    http_response_code(400);
    echo json_encode(["error" => 10, "message" => "Invalid file_url"]);
    exit;
}

// 不知为啥我这回传会多一个p文件夹，移除开头的斜杠，避免路径问题
$path = ltrim($path, '/');
if (strpos($path, 'p/') === 0) {
    $path = substr($path, 2);
}
$filename = $path;

// 读取请求体
if (($body_stream = file_get_contents("php://input")) === FALSE) {
    http_response_code(500);
    echo json_encode(["error" => 1, "message" => "Failed to read input stream"]);
    exit;
}

// 解析 JSON
$data = json_decode($body_stream, TRUE);
if ($data === null) {
    http_response_code(400);
    echo json_encode(["error" => 7, "message" => "Invalid JSON data"]);
    exit;
}

// 检查 status 是否存在
if (!isset($data["status"])) {
    http_response_code(400);
    echo json_encode(["error" => 9, "message" => "Missing 'status' parameter"]);
    exit;
}

// 获取 Alist token 的函数
function get_alist_token($config) {
    global $cached_token, $token_expires;
    $current_time = time();
    if ($cached_token && $current_time < $token_expires) {
        return $cached_token;
    }

    $post_response = curl_post(
        $config['alist']['base_url'] . "/api/auth/login",
        ["Content-Type: application/json"],
        json_encode([
            "username" => $config['alist']['username'],
            "password" => $config['alist']['password']
        ])
    );
    $post_response_data = json_decode($post_response, TRUE);
    if (!isset($post_response_data["data"]["token"])) {
        return null;
    }
    $cached_token = $post_response_data["data"]["token"];
    $token_expires = $post_response_data["data"]["exp"] ?? $current_time + 300;
    return $cached_token;
}

// 保存文档的通用函数
function save_document($data, $config, $input_file_url) {
    $downloadUri = $data["url"];
    if (($new_data = curl_get_contents($downloadUri)) === FALSE) {
        return ["error" => 2, "message" => "Failed to download file"];
    }

    // 使用全局变量 $filename，避免重复解析路径
    global $filename;
    $path = $filename;

    $max_retries = 3;
    for ($retry = 0; $retry < $max_retries; $retry++) {
        $token = get_alist_token($config);
        if ($token === null) {
            return ["error" => 3, "message" => "Failed to get token"];
        }
        
        $token = trim($token);
        $put_response = curl_put(
            $config['alist']['base_url'] . "/api/fs/put",
            [
                "Authorization: " . trim($token),
                "File-Path: " . $path,
                "As-Task: true",
                "Content-Length: " . strlen($new_data),
                "Content-Type: application/octet-stream"
            ],
            $new_data
        );

        if ($put_response === FALSE) {
            return ["error" => 4, "message" => "Failed to upload file"];
        }

        $put_response_data = json_decode($put_response, TRUE);
        if ($put_response_data["code"] === 401) {
            // 清除缓存的令牌
            global $cached_token, $token_expires;
            $cached_token = null;
            $token_expires = 0;

            if ($retry < $max_retries - 1) {
                sleep(5);
                continue;
            }
            return ["error" => 3, "message" => "Token is invalidated after multiple retries"];
        }

        if (isset($put_response_data["data"]["task"]["id"])) {
            $task_id = $put_response_data["data"]["task"]["id"];
            $max_wait = 60;
            $wait_interval = 2;
            $start_time = time();
            
            for ($i = 0; $i < $max_wait; $i += $wait_interval) {
                $task_response = curl_get(
                    $config['alist']['base_url'] . "/api/task/" . $task_id,
                    ["Authorization: " . trim($token)]
                );
                $task_data = json_decode($task_response, TRUE);
                
                if ($task_data["code"] === 200) {
                    $task_status = $task_data["data"]["status"] ?? "unknown";
                    if ($task_status === "finished") {
                        return ["error" => 0, "message" => "Success"];
                    } elseif ($task_status === "error") {
                        $error_msg = $task_data["data"]["error"] ?? "Unknown error";
                        return ["error" => 4, "message" => "Upload task failed: " . $error_msg];
                    }
                }
                
                sleep($wait_interval);
            }
            
            return ["error" => 4, "message" => "Upload task timed out"];
        }

        return ["error" => 0, "message" => "Success"];
    }
    return ["error" => 3, "message" => "Failed to upload file after multiple retries"];
}

// 处理不同状态码
switch ($data["status"]) {
    case 1: // 文档已打开
        $token = get_alist_token($config);
        if ($token === null) {
            http_response_code(500);
            echo json_encode(["error" => 3, "message" => "Failed to get token"]);
        } else {
            http_response_code(200);
            echo json_encode(["error" => 0, "message" => "Document opened"]);
        }
        break;
    case 2: // 保存事件
    case 6: // 文档关闭
        $result = save_document($data, $config, $input_file_url);
        if ($result["error"] !== 0) {
            http_response_code(500);
            echo json_encode($result);
        } else {
            http_response_code(200);
            echo json_encode($result);
        }
        break;
    default:
        http_response_code(500);
        echo json_encode(["error" => 5, "message" => "Unknown status code"]);
        break;
}
?>
