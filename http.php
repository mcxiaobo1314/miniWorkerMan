<?php

class Http
{
    /**
     * 存放select类的初始化
     */
    public static $globalEvent;

    /**
     * 协议转换数组
     */
    protected $builtinTransports = array(
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp',
    );

    /**
     * 默认协议
     */
    protected $transport = "tcp";


    /**
     * 协议命名空间字符串
     */
    protected $protocol;
    
    /**
     * 流服务内容
     */
    protected $context;

    /**
     * 主socket
     */
    protected $mainSocket;


    const EV_READ = 1;

    /**
     * worker id
     */
    protected $workerId;

    /**
     * 所有woeker的集合数组
     */
    protected static $workers = array();

    /**
     * socket名称
     */
    protected $socketName;

    protected $pauseAccept = true;


    /**
     * 构造函数
     */
    public function __construct($socket_name = "", array $context_option = array())
    {
        $this->workerId                    = \spl_object_hash($this);
        static::$workers[$this->workerId] = $this;
        if ($socket_name) {
            $this->socketName = $socket_name;
            if (!isset($context_option['socket']['backlog'])) {
                $context_option['socket']['backlog'] = 102400;
            }
            $this->context = stream_context_create($context_option);
        }
    }

    /**
     * 执行函数
     */
    public static  function runAll()
    {
        reset(static::$workers);
        $worker = current(static::$workers);
        static::$globalEvent = new select();
        $worker->listen();
        \restore_error_handler();
        static::$globalEvent->loop();
    }

    
    public function acceptConnection($socket)
    {
        set_error_handler(function(){});
        $new_socket = stream_socket_accept($socket, 0, $remote_address);
        restore_error_handler();

        if (!$new_socket) {
            return;
        }

        new TcpConnection($new_socket,$remote_address);

    }

    /**
     * 监听
     */
    protected function listen()
    {
        if(!$this->socketName)
        {
            return ;
        }
        if (!$this->mainSocket) {
            $local_socket = $this->paserSchemeAddress();

            $flags = $this->transport === 'udp' ? \STREAM_SERVER_BIND : \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN;
            $errno = 0;
            $errmsg = '';

            stream_context_set_option($this->context, 'socket', 'so_reuseport', 1);

            $this->mainSocket = stream_socket_server($local_socket,$errno,$errmsg,$flags,$this->context);

            if(!$this->mainSocket)
            {
                throw new Exception($errmsg);
            }

            //而这里把stream转换成了sokect类型了
            if(function_exists('socket_import_stream') && $this->builtinTransports[$this->transport] === 'tcp')
            {
                set_error_handler(function(){});
                $socket = socket_import_stream($this->mainSocket);
                socket_set_option($socket,SOL_SOCKET,SO_KEEPALIVE,1);
                socket_set_option($socket,SOL_TCP,TCP_NODELAY,1);
                restore_error_handler();
            }

            //设置不阻塞模式 
            stream_set_blocking($this->mainSocket,false);
        }

        $this->accept();
    }

    protected function accept()
    {
        if (static::$globalEvent && true === $this->pauseAccept && $this->mainSocket) {
            if ($this->transport !== 'udp') {
                static::$globalEvent->add($this->mainSocket, self::EV_READ, array($this, 'acceptConnection'));
            }
            $this->pauseAccept = false;
        }
    }

    
    /**
     * 解析协议头
     */
    protected function paserSchemeAddress()
    {
        if (!$this->socketName) {
            return;
        }
        list($scheme, $address) = \explode(':', $this->socketName, 2);

        if(!isset($this->builtinTransports[$scheme]))
        {
            //就用协议解析类命名空间
            $this->protocol = 'http';
        }
        else
        {
            $this->transport = $scheme;
        }

        return $this->builtinTransports[$this->transport].':'.$address;
    }

}

class Select
{
    protected $allEvents = [];

    protected $readFds = array();

    protected $exceptFds = array();

    protected $writeFds  = array();

    protected $selectTimeOut = 100000000;

    protected $_scheduler = null;

    const EV_READ = 1;

    /**
     * Construct.
     */
    public function __construct()
    {
        // Init SplPriorityQueue.
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * 添加
     */
    public function add($fd,$flag,$func,$args = array())
    {
        $fd_key          = (int)$fd;
        $this->allEvents[$fd_key][$flag] = array($func, $fd);
        $this->readFds[$fd_key] = $fd;

        return true;
    }


    public function loop()
    {
        while (1) {
            $read   = $this->readFds;
            $write  = $this->writeFds;
            $except = $this->exceptFds;
            $ret    = false;

            if($read ||  $write  || $except)
            {
                try {
                    $ret = @stream_select($read, $write, $except, 0, $this->selectTimeOut);
                } catch (\Exception $e) {} catch (\Error $e) {}
            }
            else
            {
                $this->selectTimeOut >= 1 && usleep((int)$this->selectTimeOut);
                $ret = false;
            }

         
            if (!$ret) {
                continue;
            }
        
            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->allEvents[$fd_key][self::EV_READ])) {
                        \call_user_func_array($this->allEvents[$fd_key][self::EV_READ][0],
                        array($this->allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            
            }
        
        }
    }

}


class TcpConnection 
{
    protected $socket;

    const EV_READ = 1;

    protected $bytesRead;

    protected $recvBuffer;

    protected $isPaused = false;

    /**
     * 当前数据包的长度
     */
    protected $currentPackageLength;

    /**
     * 最大数据包的大小
     */
    protected $maxPackageSize = 1048576;
    

    public function __construct($socket, $remote_address = '')
    {
        $this->socket = $socket;

        stream_set_blocking($this->socket, 0);
        stream_set_read_buffer($this->socket, 0);

        Http::$globalEvent->add($this->socket,self::EV_READ,array($this, 'baseRead'));
    }


    public function baseRead($socket, $check_eof = true)
    {
        echo "baseRead";
        $buffer = '';
        try {
            $buffer = @fread($socket, 65535);
        } catch (\Exception $e) {} catch (\Error $e) {}

        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
            }
        }else
        {
            $this->bytesRead += strlen($buffer);
            $this->recvBuffer .= $buffer;
        }

        while ($this->recvBuffer !== '' && !$this->isPaused) 
        {
 
            if ($this->currentPackageLength) 
            {
                if ($this->currentPackageLength > strlen($this->recvBuffer)) 
                {
                    break;
                }
            }
     
            $crlf_pos = strpos($this->recvBuffer, "\r\n\r\n");
     
            $head_len = $crlf_pos + 4;
            $method = strstr($this->recvBuffer, ' ', true);
            if ($method === 'GET')
            {
                $this->currentPackageLength = $head_len;
            }
            
            if ($this->currentPackageLength === 0) {
                break;
            }
            elseif ($this->currentPackageLength > 0 && $this->currentPackageLength <= $this->maxPackageSize) 
            {
                if ($this->currentPackageLength > strlen($this->recvBuffer)) 
                {
                    break;
                }
            } 
     
            if (strlen($this->recvBuffer) === $this->currentPackageLength) 
            {
                $one_request_buffer = $this->recvBuffer;
                $this->recvBuffer  = '';
            }
            $this->currentPackageLength = 0;
            //然后解析$one_request_buffer 数据包，代码就忽略了具体可以看HTTP协议里面Request类
            //后面就调用onMessage 自定义函数 这里做个忽略了...


            $this->send("12333333");
        }
        
    }


    public function send($send_buffer)
    {
        $ext_header = '';
        $body_len = strlen($send_buffer);
        $send = "HTTP/1.1 200 OK\r\nServer: xiaobo\r\n{$ext_header}Connection: keep-alive\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $body_len\r\n\r\n$send_buffer";
        $len = 0;
        $len = @fwrite($this->socket, $send);
        fclose($this->socket);
    }

}
$http = new Http("http://0.0.0.0:8053");
Http::runAll();
