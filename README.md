# SugarTidewaysProfiling

## Installation
* Clone this repository and enter the cloned directory
* Retrieve the Sugar Module Packager dependency by running: `composer install`
* Generate the installable .zip Sugar module with: `./vendor/bin/package 0.1`
* Install the generated module into the instance
* Configure the instance with the following settings:

```
$sugar_config['xhprof_config']['enable'] = true;
$sugar_config['xhprof_config']['manager'] = 'SugarTidewaysProf';
$sugar_config['xhprof_config']['log_to'] = '../profiling'; // configure path to a writable directory
$sugar_config['xhprof_config']['sample_rate'] = 1;
$sugar_config['xhprof_config']['flags'] = 0;
```
