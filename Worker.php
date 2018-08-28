<?php
/**
 * 通过进程控制实现一个简易的PHPServer.
 *
 * @author haobaif <i@fanhaobai.com>
 */

namespace PHPServer;

/**
 * Class Worker.
 */
class Worker
{

    /**
     * status状态值.
     */
    const STATUS_STARTING  = 1;
    const STATUS_RUNNING   = 2;
    const STATUS_SHUTDOWN  = 3;
    const STATUS_RELOADING = 4;

    /**
     * 是否守护态运行.
     */
    static $daemonize = false;

    /**
     * pid文件.
     */
    static $pidFile = '/var/run/php-server.pid';

    /**
     * 标准输出.
     */
    static $stdoutFile = '/dev/null';

    /**
     * worker数量.
     */
    static $workerCount = 2;

    /**
     * 状态.
     */
    static $status;

    /**
     * master进程pid.
     */
    protected static $_masterPid;

    /**
     * worker进程pid.
     */
    protected static $_workers = array();

    /**
     * 守护态运行.
     */
    protected static function daemonize()
    {
        if (!static::$daemonize) {
            return;
        }

        umask(0);
        $pid = pcntl_fork();
        if (-1 === $pid) {
            exit("process fork fail\n");
        } elseif ($pid > 0) {
            exit(0);
        }

        // 将当前进程提升为会话leader
        if (-1 === posix_setsid()) {
            exit("process setsid fail\n");
        }

        // 再次fork以避免SVR4这种系统终端再一次获取到进程控制
        $pid = pcntl_fork();
        if (-1 === $pid) {
            exit("process fork fail\n");
        } elseif (0 !== $pid) {
            exit(0);
        }

        echo "\n";
    }

    /**
     * 保存master进程pid以实现stop和reload
     */
    protected static function saveMasterPid()
    {
        // 保存pid以实现重载和停止
        static::$_masterPid = posix_getpid();
        if (false === file_put_contents(static::$pidFile, static::$_masterPid)) {
            exit("can not save pid to" . static::$pidFile . "\n");
        }

        echo "PHPServer start\t \033[32m [OK] \033[0m\n";
    }

    /**
     * 解析命令参数.
     */
    protected static function parseCmd()
    {
        global $argv;
        $command = isset($argv[1]) ? $argv[1] : '';
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // 获取master的pid和存活状态
        $masterPid = is_file(static::$pidFile) ? file_get_contents(static::$pidFile) : 0;
        $masterAlive = $masterPid ? static::isAlive($masterPid) : false;

        if ($masterAlive) {
            if ($command === 'start') {
                exit("PHPServer already running\n");
            }
        } else {
            if ($command && $command !== 'start' && $command !== 'restart') {
                exit("PHPServer not run\n");
            }
        }

        switch ($command) {
            case 'start':
                if ($command2 === '-d') {
                    static::$daemonize = true;
                }
                break;
            case 'stop':
                echo("PHPServer stopping ...\n");

                // 给master发送stop信号
                posix_kill($masterPid, SIGINT);

                $timeout = 5;
                $startTime = time();
                while (static::isAlive($masterPid)) {
                    usleep(1000);

                    if (time() - $startTime >= $timeout) {
                        exit("PHPServer stop fail\n");
                    }
                }

                exit("PHPServer stop success\n");
            case 'reload':
                echo("PHPServer reloading ...\n");

                // 给master发送reload信号
                posix_kill($masterPid, SIGUSR1);
                exit(0);
            default:
                $usage = "Usage: Commands [mode] \n\nCommands:\nstart\t\tStart worker.\nstop\t\tStop worker.\nreload\t\tReload codes.\n\nOptions:\n-d\t\tto start in DAEMON mode.\n\nUse \"--help\" for more information about a command.\n";
                exit($usage);
        }

    }

    /**
     * 环境检测.
     */
    protected static function checkEnv()
    {
        // 只能运行在cli模式
        if (php_sapi_name() != "cli") {
            exit("PHPServer only run in command line mode\n");
        }
    }

    /**
     * master进程监控worker.
     */
    protected static function monitor()
    {
        static::$status = static::STATUS_RUNNING;

        while (1) {
            // 这两处捕获触发信号,很重要
            pcntl_signal_dispatch();

            // 刮起当前进程的执行直到一个子进程退出或接收到一个信号
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);

            pcntl_signal_dispatch();

            if ($pid >= 0) {
                // 维持worker数
                static::keepWorkerNumber();
            }

            // 其他你想监控的
        }
    }

    /**
     * 维持worker进程数量,防止worker异常退出
     */
    protected static function keepWorkerNumber()
    {
        $allWorkerPid = static::getAllWorkerPid();
        foreach ($allWorkerPid as $index => $pid) {
            if (!static::isAlive($pid)) {
                unset(static::$_workers[$index]);
            }
        }

        static::forkWorkers();
    }

    /**
     * 设置进程名.
     *
     * @param string $title 进程名.
     */
    protected static function setProcessTitle($title)
    {
        if (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        } elseif (version_compare(phpversion(), "5.5", "ge") && function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }

    /**
     * 关闭标准输出和错误输出.
     */
    protected static function resetStdFd()
    {
        global $STDERR, $STDOUT;

        //重定向标准输出和错误输出
        @fclose(STDOUT);
        fclose(STDERR);
        $STDOUT = fopen(static::$stdoutFile, 'a');
        $STDERR = fopen(static::$stdoutFile, 'a');
    }

    /**
     * 创建所有worker进程.
     */
    protected static function forkWorkers()
    {
        while (count(static::$_workers) < static::$workerCount) {
            static::forkOneWorker();
        }
    }

    /**
     * 创建一个worker进程.
     */
    protected static function forkOneWorker()
    {
        $pid = pcntl_fork();

        // 父进程
        if ($pid > 0) {
            static::$_workers[] = $pid;
        } else if ($pid === 0) { // 子进程
            static::setProcessTitle('PHPServer: worker');

            // 子进程会阻塞在这里
            static::run();

            // 子进程退出
            exit(0);
        } else {
            throw new \Exception("fork one worker fail");
        }
    }

    /**
     * 初始化.
     */
    protected static function init()
    {
        static::checkEnv();

        static::setProcessTitle('PHPServer: master');
        static::$status = static::STATUS_STARTING;
    }

    /**
     * 安装信号处理器.
     */
    protected static function installSignal()
    {
        // SIGINT
        pcntl_signal(SIGINT, array('\PHPServer\Worker', 'signalHandler'), false);
        // SIGTERM
        pcntl_signal(SIGTERM, array('\PHPServer\Worker', 'signalHandler'), false);

        // SIGUSR1
        pcntl_signal(SIGUSR1, array('\PHPServer\Worker', 'signalHandler'), false);
        // SIGQUIT
        pcntl_signal(SIGQUIT, array('\PHPServer\Worker', 'signalHandler'), false);

        // 忽略信号
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        pcntl_signal(SIGHUP, SIG_IGN, false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * 信号处理器.
     *
     * @param integer $signal 信号.
     */
    protected static function signalHandler($signal)
    {
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
                static::stop();
                break;
            case SIGQUIT:
            case SIGUSR1:
                static::reload();
                break;
            default:
                break;
        }
    }

    /**
     * 获取所有worker进程pid.
     *
     * @return array
     */
    protected static function getAllWorkerPid()
    {
        return array_values(static::$_workers);
    }

    /**
     * 强制kill掉一个进程.
     *
     * @param integer $pid 进程pid.
     */
    protected static function forceKill($pid)
    {
        // 进程是否存在
        if (static::isAlive($pid)) {
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * 进程是否存活.
     *
     * @param mixed $pids 进程pid.
     *
     * @return bool
     */
    protected static function isAlive($pids)
    {
        if (!is_array($pids)) {
            $pids = array($pids);
        }

        foreach ($pids as $pid) {
            if (posix_kill($pid, 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 停止所有worker进程.
     */
    protected static function stopAllWorkers()
    {
        $allWorkerPid = static::getAllWorkerPid();
        foreach ($allWorkerPid as $workerPid) {
            posix_kill($workerPid, SIGTERM);
        }

        // 子进程退出异常,强制kill
        usleep(1000);
        if (static::isAlive($allWorkerPid)) {
            foreach ($allWorkerPid as $workerPid) {
                static::forceKill($workerPid);
            }
        }

        // 清空worker实例
        static::$_workers = array();
    }

    /**
     * 停止.
     */
    protected static function stop()
    {
        static::$status = static::STATUS_SHUTDOWN;

        // 主进程给所有子进程发送退出信号
        if (static::$_masterPid === posix_getpid()) {
            static::stopAllWorkers();

            if (is_file(static::$pidFile)) {
                @unlink(static::$pidFile);
            }
            exit(0);

        } else { // 子进程退出

            // 退出前可以做一些事
            exit(0);
        }
    }

    /**
     * 重新加载.
     */
    protected static function reload()
    {
        static::$status = static::STATUS_RELOADING;

        static::stopAllWorkers();

        $allWorkPid = static::getAllWorkerPid();
        while (static::isAlive($allWorkPid)) {
            usleep(10);
        }

        static::forkWorkers();
    }

    /**
     * worker进程任务.
     */
    public static function run()
    {
        static::$status = static::STATUS_RUNNING;

        // 模拟调度,实际用event实现
        while (1) {
            // 捕获信号
            pcntl_signal_dispatch();

            call_user_func(function () {
                // do something
                usleep(200);
            });
        }
    }

    /**
     * 启动PHPServer.
     */
    public static function runAll()
    {
        static::init();

        static::parseCmd();
        static::daemonize();
        static::saveMasterPid();

        static::installSignal();
        static::forkWorkers();

        static::resetStdFd();
        static::monitor();
    }

}
