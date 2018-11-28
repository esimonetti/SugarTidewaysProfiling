<?php

// Enrico Simonetti
// enricosimonetti.com
//
// Original work: https://gist.github.com/lblockken/78a59273f2460b36eb127a7c2ee510a1
//
// 2018-11-28 on Sugar 8.0.2 with PHP 7.1
// filename: custom/include/SugarXHprof/SugarTidewaysProf.php
//
// Compatible with both tideways and tideways_xhprof extensions, giving precendece to the latest one tideways_xhprof
// Read more on: https://tideways.com/profiler/blog/releasing-new-tideways-xhprof-extension

/**
 * Class SugarTidewaysProf
 *
 * To enable profiling install PHP tideways module and add these lines to the config_override.php:
 * $sugar_config['xhprof_config']['enable'] = true;
 * $sugar_config['xhprof_config']['manager'] = 'SugarTidewaysProf';
 * $sugar_config['xhprof_config']['log_to'] = '../profiling';
 * $sugar_config['xhprof_config']['sample_rate'] = 1;
 * $sugar_config['xhprof_config']['flags'] = 0;
 *
 */

use Sugarcrm\Sugarcrm\Performance\Dbal\XhprofLogger;

class SugarTidewaysProf extends SugarXHprof 
{
    protected static $activeTidewaysProfiler;

    protected static $tidewaysProfilers = [
        'tideways_xhprof',
        'tideways',
    ];

    protected static function callTidewaysProfilerEnable()
    {
        if (static::isTidewaysProfilerEnabled()) {
            if (static::$activeTidewaysProfiler == 'tideways_xhprof') {
                // does not support ignored functions
                tideways_xhprof_enable(static::$flags);
            } else if (static::$activeTidewaysProfiler == 'tideways') {
                tideways_enable(static::$flags, array(
                    'ignored_functions' => static::$ignored_functions
                ));
            }
        }
    }

    protected static function callTidewaysProfilerDisable()
    {
        if (static::isTidewaysProfilerEnabled()) {
            if (static::$activeTidewaysProfiler == 'tideways_xhprof') {
                return tideways_xhprof_disable();
            } else if (static::$activeTidewaysProfiler == 'tideways') {
                return tideways_disable();
            }
        }
        return false;
    }

    protected static function isTidewaysProfilerEnabled()
    {
        foreach (static::$tidewaysProfilers as $profiler) {
            if (extension_loaded($profiler)) {
                static::$enable = true;
                static::$activeTidewaysProfiler = $profiler;           
                return true;
            }
        } 

        static::$enable = false;
        return false;
    }
 
    /**
     * Populates configuration from $sugar_config to self properties
     */
    protected static function loadConfig()
    {
        if (!empty($GLOBALS['sugar_config']['xhprof_config'])) {
            foreach ($GLOBALS['sugar_config']['xhprof_config'] as $k => $v) {
                if (isset($v) && property_exists(static::$manager, $k)) {
                    static::${$k} = $v;
                }
            }
        }

        if (!static::$enable) {
            return;
        }

        if (!static::isTidewaysProfilerEnabled()) {
            return;
        }

        if (static::$save_to == 'file') {
            // using default directory for profiler if it is not set
            if (empty(static::$log_to)) {
                static::$log_to = ini_get('xhprof.output_dir');
                if (empty(static::$log_to)) {
                    static::$log_to = sys_get_temp_dir();

                    error_log("Warning: Must specify directory location for XHProf runs. " .
                        "Trying " . static::$log_to . " as default. You can either set "
                        . "\$sugar_config['xhprof_config']['log_to'] sugar config " .
                        "or set xhprof.output_dir ini param.");
                }
            }

            // disabling profiler if directory is not exist or is not writable
            if (is_dir(static::$log_to) == false || is_writable(static::$log_to) == false) {
                static::$enable = false;
            }
        }

        // enable SugarXhprofLogger class for Doctrine
        if (static::$enable && empty($GLOBALS['installing'])) {
            $logger = DBManagerFactory::getDbalLogger();
            $logger->addLogger(new XhprofLogger(static::$instance));
        }
    }

    /**
     * Tries to enabled xhprof if all settings were passed
     */
    public function start()
    { 
        if (static::$enable == false) {
            return;
        }

        if (static::$sample_rate == 0) {
            static::$enable = false;
            return;
        }

        $rate = 1 / static::$sample_rate * 100;
        if (rand(0, 100) > $rate) {
            static::$enable = false;
            return;
        }

        $this->resetSqlTracker();
        $this->resetElasticTracker();

        register_shutdown_function(array(
            $this,
            'end'
        ));

        $this->startTime = isset($_SERVER['REQUEST_TIME_FLOAT']) ? $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);

        static::callTidewaysProfilerEnable();
    }

    /**
     * Tries to collect data from XHprof after call of 'start' method
     */
    public function end()
    {
        if (!static::$enable) {
            return;
        }
        static::$enable = false;
        $origMemLimit = ini_get('memory_limit');
        ini_set('memory_limit', static::$memory_limit);

        $xhprofData = static::callTidewaysProfilerDisable();

        $wallTime = $xhprofData['main()']['wt'];

        if ($wallTime > static::$filter_wt * 1000) {
            $sqlQueries = count($this->sqlTracker['sql']);
            $elasticQueries = count($this->elasticTracker['queries']);
            $action = static::cleanActionString(static::detectAction());

            $runName = implode('d', array(
                    str_replace('.', 'd', $this->startTime),
                    $wallTime,
                    $sqlQueries,
                    $elasticQueries
                )) . '.' . $action;

            $this->saveRun($runName, $xhprofData);
        }

        ini_set('memory_limit', $origMemLimit);
    }
}
