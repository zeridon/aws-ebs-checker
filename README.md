# AWS EBS Nagios checker

## What is this
This is a nagios based check that can be used to check status of attached EBS volumes and their snapshots.

## How to use
Configure it via NRPE and execute at will. For initial testing manual execution is advised. In order to use it you must enter your credentials in the global `$config` array or make sure the appropriate environment values are specified and available. More information can be found in [Providing Credentials to the SDK](http://docs.aws.amazon.com/aws-sdk-php/guide/latest/credentials.html) Amazon documentation.

There are 2 internal applications supported:

 * check-ebs - checks status for all attached EBS volumes.
 * check-snapshots - checks that the attached EBS volumes have at least a certain number of snapshots in the last 15 days and that those snapshots are healthy. Warning and critical levels are configurable via the `--warning|-w` and `--critical|-c` options respectively. Due to the usage of `symfony/console` the options are completely optional, but have fallback values of `14` and `7` respectively.

Help is available via the --help option.

## Hacking/Contributing
Patches, improvements, suggestions, pull requests are welcome.

The code is relatively straight forward (albeit a bit duplicated) and shouldn't be hard on the eyes.

## Example
Example of a normal check

```
root@i-11111111:~$ aws-ebs-check-status.php check-ebs
OK: Volume vol-11111111 OK;
```

Example of critical alarm. Note that if an alarm is raised for more than one EBS volume, all will be reported.

```
root@i-11111111:~$ aws-ebs-check-status.php check-snapshots
CRITICAL: Volume vol-11111111 has less than 7 snapshots;
```

This shows a machine with 4 EBS volumes attached having just 2 snapshots each.

```
root@i-11111111:~$ aws-ebs-check-status.php check-snapshots -w 2 -c 1
OK: snap-e4396c1e OK; snap-e3edb119 OK; snap-c6396c3c OK; snap-d1edb12b OK; snap-f9396c03 OK; snap-15ecb0ef OK; snap-a7396c5d OK; snap-a8edb152 OK;
```

## Dependencies
This check has been build with composer in mind to manage the dependencies.

Beside that basic requirement the rest needed is:

 * PHP - preferable 5.3.3 and up
 * AWS SDK for PHP - it is managed via composer
 * symmfony/console - for easier options handling
