#!/usr/bin/env php
<?php
error_reporting(-1);

date_default_timezone_set('UTC');
$BASEDIR=dirname(__FILE__);

require($BASEDIR . '/vendor/autoload.php');

use Symfony\Component\Console;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\Common\Aws;

# Nagios states
define('STATE_OK', 0);
define('STATE_WARNING', 1);
define('STATE_CRITICAL', 2);
define('STATE_UNKNOWN', 3);

# global AWS config
$config = array(
#	'key' => 'YOUR_AWS_ACCESS_KEY_ID',
#	'secret' => 'YOUR_AWS_SECRET_ACCESS_KEY',
);

class CheckEBSStatus extends Console\Command\Command {

	protected function configure(){
		$this->setDescription('Checks EBS status');
		$this->setHelp('Checks the EBS status for all attached volumes and report to nagios');
		$this->addOption('ignore', 'i', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Ignore this volume', null);
		$this->setName('check-ebs');
	}

	protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output){
		# Pull the global config
		global $config;

		# get location info
		$sInstanceID = file_get_contents('http://169.254.169.254/latest/meta-data/instance-id');
		$sAZ = file_get_contents('http://169.254.169.254/latest/meta-data/placement/availability-zone');
		$sRegion = preg_replace('/^(.*)([0-9]{1})([a-zA-Z]{1})/', '$1$2', $sAZ);

		$_ret_state=STATE_OK;
		$_ret_detail='';
		$_ret_flag_crit=FALSE;
		$_ret_flag_warn=FALSE;
		$_ret_flag_unkn=FALSE;

		$config['region']=$sRegion;

		# connect to AWS
		$aws = Aws::factory($config);
		$client = $aws->get('Ec2');

		# get all the attached volumes to the instance
		$result = $client->describeVolumes(
			array(
				'Filters' => array(array('Name' => 'attachment.instance-id','Values' => array($sInstanceID)))
			)
		);

		# Now iterate over the volumes checking status
		$_volumes = $result['Volumes'];
		foreach($_volumes as $vol){
			foreach($input->getOption('ignore') as $_ignored_vol){
				if (preg_match('/' . $_ignored_vol . '/', $vol['VolumeId'])){
					# we match get out of the loop
					break;
				} else {
					$result = $client->describeVolumeStatus(array('VolumeIds' => array($vol['VolumeId'])));
					$_volstat = $result['VolumeStatuses'];
					foreach($_volstat as $vstat){
						switch ($vstat['VolumeStatus']['Status']){
							case 'ok':
								$_ret_detail=$_ret_detail . 'Volume ' . $vol['VolumeId'] . ' OK; ';
								break;
							case 'impaired':
								$_ret_flag_crit=TRUE;
								$_ret_detail=$_ret_detail . "Impaired VOLUME (I/O DISABLED)" . $vol['VolumeId'] . '; ';
								break;
							case 'warning':
								$_ret_flag_warn=TRUE;
								$_ret_detail=$_ret_detail . 'Volume ' . $vol['VolumeId'] . ' warning; ';
								break;
							case 'insufficient-data':
								$_ret_flag_unkn=TRUE;
								$_ret_detail=$_ret_detail . 'Volume ' . $vol['VolumeId'] . ' unknown; ';
								break;
						}
					}
				}
				# get out of the loop
				break;
			}
		}
		if ($_ret_flag_unkn) { $_ret_detail='UNKNOWN: ' . $_ret_detail ; $_ret_state=STATE_UNKNOWN; }
		elseif ($_ret_flag_warn) { $_ret_detail='WARNING: ' . $_ret_detail ; $_ret_state=STATE_WARNING; }
		elseif ($_ret_flag_crit) { $_ret_detail='CRITICAL: ' . $_ret_detail ; $_ret_state=STATE_CRITICAL; }
		else { $_ret_detail='OK: ' . $_ret_detail ; $_ret_state=STATE_OK; }

		# NOW REPORT
		$output->writeln(trim($_ret_detail));
		exit($_ret_state);
	}
}

class CheckEBSSnapshots extends Console\Command\Command {

	protected function configure(){
		$this->setDescription('Checks EBS snapshots');
		$this->setHelp('Checks the EBS snapshots for all attached volumes and report to nagios');
		$this->setName('check-snapshots');
		$this->addOption('warning', 'w', InputOption::VALUE_REQUIRED, 'Warning level', 14);
		$this->addOption('critical', 'c', InputOption::VALUE_REQUIRED, 'Critical level', 7);
		$this->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'How many days back to check the snapshots', 14);
		$this->addOption('ignore', 'i', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Ignore this volume', null);
	}

	protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output){
		# Pull the global config
		global $config;

		# get location info
		$sInstanceID = file_get_contents('http://169.254.169.254/latest/meta-data/instance-id');
		$sAZ = file_get_contents('http://169.254.169.254/latest/meta-data/placement/availability-zone');
		$sRegion = preg_replace('/^(.*)([0-9]{1})([a-zA-Z]{1})/', '$1$2', $sAZ);

		$_ret_state=STATE_OK;
		$_ret_detail='';
		$_ret_flag_crit=FALSE;
		$_ret_flag_warn=FALSE;
		$_ret_flag_unkn=FALSE;

		$config['region']=$sRegion;

		# connect to AWS
		$aws = Aws::factory($config);
		$client = $aws->get('Ec2');

		# get all the attached volumes to the instance
		$result = $client->describeVolumes(
			array(
				'Filters' => array(array('Name' => 'attachment.instance-id','Values' => array($sInstanceID))),
			)
		);

		# Now iterate over the volumes checking status
		$_volumes = $result['Volumes'];
		foreach($_volumes as $vol){
			foreach($input->getOption('ignore') as $_ignored_vol){
				if (preg_match('/' . $_ignored_vol . '/', $vol['VolumeId'])){
					# matches ignored volume ...
					# get out of the loop
					break;
				} else {
					unset($dates);
					# create an array to filter snapshots
					for ($i=0 ; $i <= $input->getOption('period') ; $i++){
						$dates[]=date('Y-m-d', strtotime("-$i days")) . '*';
					}
		
					$result = $client->describeSnapshots(
						array('Filters' => array(
							array('Name' => 'volume-id', 'Values' => array($vol['VolumeId'])),
							array('Name' => 'start-time', 'Values' => $dates),
						))
					);
					$_snapshots = $result['Snapshots'];
					if (count($_snapshots) < $input->getOption('critical')){
						$_ret_flag_crit=TRUE;
						$_ret_detail=$_ret_detail . 'Volume ' . $vol['VolumeId'] . ' has less than ' . $input->getOption('critical') . ' snapshots; ';
						continue;
					}
					if (count($_snapshots) < $input->getOption('warning')){
						$_ret_flag_warn=TRUE;
						$_ret_detail=$_ret_detail . 'Volume ' . $vol['VolumeId'] . ' has less than ' . $input->getOption('warning') . ' snapshots; ';
						continue;
					}
		
					foreach($_snapshots as $snapshot){
						switch($snapshot['State']){
						case 'completed':
							$_ret_detail = $_ret_detail . $snapshot['SnapshotId'] . ' OK; ';
							break;
						case 'pending':
							$_ret_detail = $_ret_detail . $snapshot['SnapshotId'] . ' In Progress; ';
							break;
						case 'error':
							$_ret_flag_crit=TRUE;
							$_ret_detail = $_ret_detail . $snapshot['SnapshotId'] . ' FAILED; ';
							break;
						}
					}
					# get out of the loop
					break;
				}
			}
		}
		if ($_ret_flag_unkn) { $_ret_detail='UNKNOWN: ' . $_ret_detail ; $_ret_state=STATE_UNKNOWN; }
		elseif ($_ret_flag_warn) { $_ret_detail='WARNING: ' . $_ret_detail ; $_ret_state=STATE_WARNING; }
		elseif ($_ret_flag_crit) { $_ret_detail='CRITICAL: ' . $_ret_detail ; $_ret_state=STATE_CRITICAL; }
		else { $_ret_detail='OK: ' . $_ret_detail ; $_ret_state=STATE_OK; }

		# NOW REPORT
		$output->writeln(trim($_ret_detail));
		exit($_ret_state);

	}
}

$app = new Console\Application('EBS Checker', '1.0.0');
$app->add(new CheckEBSStatus('check-ebs'));
$app->add(new CheckEBSSnapshots('check-snapshots'));
$app->run();
