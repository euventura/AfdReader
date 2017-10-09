<?php

namespace Convenia\AfdReader;

use Convenia\AfdReader\Exception\FileNotFoundException;
use Convenia\AfdReader\Exception\WrongFileTypeException;

class AfdReader
{
    /**
     * @var
     */
    private $file;

    /**
     * @var string
     */
    private $fileType;

    /**
     * @var
     */
    private $fileContents;

    /**
     * @var array
     */
    private $fileArray = [];

    /**
     * @var array
     */
    private $userArray = [];

    /**
     * @var int
     */
    private $typePosition = 9;

    /**
     * @var array
     */
    private $typeNumber = [
        'Afdt'  => [
            '1' => \Convenia\AfdReader\Registry\Afdt\Header::class,
            '2' => \Convenia\AfdReader\Registry\Afdt\Detail::class,
            '9' => \Convenia\AfdReader\Registry\Afdt\Trailer::class,
        ],
        'Afd'   => [
            '1' => \Convenia\AfdReader\Registry\Afd\Header::class,
            '2' => \Convenia\AfdReader\Registry\Afd\CompanyChange::class,
            '3' => \Convenia\AfdReader\Registry\Afd\Mark::class,
            '4' => \Convenia\AfdReader\Registry\Afd\MarkAdjust::class,
            '5' => \Convenia\AfdReader\Registry\Afd\Employee::class,
            '9' => \Convenia\AfdReader\Registry\Afd\Trailer::class,
        ],
        'Acjef' => [
            '1' => \Convenia\AfdReader\Registry\Acjef\Header::class,
            '2' => \Convenia\AfdReader\Registry\Acjef\ContractualHours::class,
            '3' => \Convenia\AfdReader\Registry\Acjef\Detail::class,
        ],
    ];

    /**
     * AfdReader constructor.
     *
     * @param $filePath
     * @param null $fileType
     * @throws FileNotFoundException
     * @throws WrongFileTypeException
     */
    public function __construct($filePath, $fileType = null)
    {
        $this->fileType = $fileType;
        $this->file = $filePath;
        $this->setFileContents();

        if ($fileType === null) {
            $this->fileType = $this->fileTypeMagic();
        }

        $this->readLines();
    }

    /**
     * check file, if exists set content.
     *
     * @method setFileContents
     */
    private function setFileContents()
    {
        if (file_exists($this->file) === false) {
            throw new FileNotFoundException($this->file);
        }

        $this->fileContents = file($this->file);
    }

    /**
     * Check file type by lines.
     *
     * @return string
     * @throws WrongFileTypeException
     */
    private function fileTypeMagic()
    {
        $trailer = ($this->fileContents[count($this->fileContents) - 2]);
        $trailer = trim($trailer);

        switch (strlen($trailer)) {
            case 34 :
                return 'Afd';
            case 55 :
                return 'Afdt';
            case 91 :
                return 'Acjef';
            default :
                throw new WrongFileTypeException(__METHOD__ . ' couldn\'t recognize this file.');
        }

    }

    /**
     * Read de Content and transforma in array.
     */
    private function readLines()
    {
        foreach ($this->fileContents as $value) {
            $this->fileArray[] = $this->translateToArray($value);
        }
    }

    /**
     * Translate line to array info.
     *
     * @param $content
     * @return array
     */
    private function translateToArray($content)
    {
        $position = 0;
        $line = [];
        $map = $this->getMap($content);
        if ($map !== false) {
            foreach ($map as $fieldMap) {
                $line[$fieldMap['name']] = substr($content, $position, $fieldMap['size']);
                if (isset($fieldMap['class'])) {
                    $field = new $fieldMap['class']($line[$fieldMap['name']]);
                    $line[$fieldMap['name']] = $field->format($line[$fieldMap['name']]);
                }
                $position = $position + $fieldMap['size'];
            }
        }

        return $line;
    }

    /**
     * Return a map by line type and file type.
     *
     * @param $content
     * @return bool
     */
    private function getMap($content)
    {
        $type = $this->getType($content);
        if (isset($this->typeNumber[$this->fileType][$type])) {
            $registry = $this->typeNumber[$this->fileType][$type];
            $class = new $registry;
            return $class->map;
        }

        return false;
    }

    /**
     * Get type line.
     *
     * @param $content
     * @return bool|string
     */
    private function getType($content)
    {
        return substr($content, $this->typePosition, 1);
    }

    /**
     * Return array by user formated.
     *
     * @return array
     */
    public function getByUser()
    {
        if ($this->fileType == 'Afd') {
            return $this->getByUserAfd();
        } elseif ($this->fileType == 'Afdt') {
            return $this->getByUserAfdt();
        } else {
            return $this->getByUserAcjef();
        }
    }

    /**
     * Return array by user formated.
     *
     * @return array
     */
    public function getAll()
    {
        if ($this->fileType == 'Afd') {
            return $this->getAllAfd();
        } elseif ($this->fileType == 'Afdt') {
            return $this->getAllAfdt();
        } else {
            return $this->getAllAcjef();
        }
    }

    /**
     * Get By User on AFD files.
     *
     * @return array
     */
    private function getByUserAfd()
    {
        $userControl = [];
        foreach ($this->fileArray as $value) {
            if ($this->isByUserCondition($value)) {
                if (!isset($userControl[$value['identityNumber']]['direction'][$value['date']->format('dmY')])) {
                    $userControl[$value['identityNumber']]['direction'][$value['date']->format('dmY')] = 'Entrada';
                    $userControl[$value['identityNumber']]['period'][$value['date']->format('dmY')] = 1;
                }
                $this->userArray[$value['identityNumber']][$value['date']->format('dmY')][$userControl[$value['identityNumber']]['period'][$value['date']->format('dmY')]][] = [
                    'sequency'  => $value['sequency'],
                    'dateTime'  => $value['date']->setTime($value['time']['hour'], $value['time']['minute']),
                    'direction' => $userControl[$value['identityNumber']]['direction'][$value['date']->format('dmY')],
                ];

                if ($userControl[$value['identityNumber']]['direction'][$value['date']->format('dmY')] == 'Entrada') {
                    $userControl[$value['identityNumber']]['direction'][$value['date']->format('dmY')] = 'Saída';
                    continue;
                }

                $userControl[$value['identityNumber']]['direction'][$value['date']->format('dmY')] = 'Entrada';
                $userControl[$value['identityNumber']]['period'][$value['date']->format('dmY')]++;
            }
        }

        return $this->userArray;
    }

    /**
     * Get By User on AFDT files.
     *
     * @return array
     */
    private function getAllAfd()
    {

        $data = [];

        foreach ($this->fileArray as $value) {
            if (!$this->isByUserCondition($value) && array_key_exists('type', $value)) {
                if ($value['type'] == 1) {
                    $data['header'] = [
                        'sequency'          => $value['sequency'],
                        'type'              => $value['type'],
                        'identityType'      => $value['identityType'],
                        'identityNumber'    => $value['identityNumber'],
                        'cei'               => $value['cei'],
                        'name'              => $value['name'],
                        'SerialNumber'      => $value['SerialNumber'],
                        'registryStartDate' => $value['registryStartDate']->format('dmY'),
                        'registryEndDate'   => $value['registryEndDate']->format('dmY'),
                        'generationDate'    => $value['generationDate']->format('dmY'),
                        'generationTime'    => $value['generationTime'],
                    ];
                }
                if ($value['type'] == 2) {
                    $data['companyChange'][] = [
                        'nsr'             => $value['nsr'],
                        'type'            => $value['type'],
                        'date'            => $value['date']->format('dmY'),
                        'time'            => $value['time'],
                        'identityType'    => $value['identityType'],
                        'idenitityNumber' => $value['idenitityNumber'],
                        'cei'             => $value['cei'],
                        'name'            => $value['name'],
                        'place'           => $value['place'],
                    ];
                }
                if ($value['type'] == 4) {
                    $data['markAdjust'][] = [
                        'nsr'        => $value['nsr'],
                        'type'       => $value['type'],
                        'dateBefore' => $value['dateBefore']->format('dmY'),
                        'timeBefore' => $value['timeBefore'],
                        'dateAfter'  => $value['dateAfter']->format('dmY'),
                        'timeAfter'  => $value['timeAfter'],
                    ];
                }
                if ($value['type'] == 5) {
                    $data['employee'][] = [
                        'nsr'            => $value['nsr'],
                        'type'           => $value['type'],
                        'date'           => $value['date']->format('dmY'),
                        'time'           => $value['time'],
                        'operation'      => $value['operation'],
                        'identityNumber' => $value['identityNumber'],
                        'name'           => $value['name'],
                    ];
                }
                if ($value['type'] == 9) {
                    $data['trailer'] = [
                        'sequency'    => $value['sequency'],
                        'numberType2' => $value['numberType2'],
                        'numberType2' => $value['numberType2'],
                        'numberType3' => $value['numberType3'],
                        'numberType4' => $value['numberType4'],
                        'numberType5' => $value['numberType5'],
                        'type'        => $value['type'],
                    ];
                }


            }
        }

        $data['mark'] = $this->getByUserAfd();

        return $data;
    }

    /**
     * Get By User on AFDT files.
     *
     * @return array
     */
    private function getAllAfdt()
    {

        $data = [];

        foreach ($this->fileArray as $value) {
            if (!$this->isByUserCondition($value) && array_key_exists('type', $value)) {

                if ($value['type'] == 1) {
                    $data['header'] = $this->header($value);
                }

                if ($value['type'] == 9) {
                    $data['trailer'] = [
                        'sequency' => $value['sequency'],
                        'type'     => $value['type'],
                    ];
                }


            }
        }

        $data['detail'] = $this->getByUserAfdt();

        return $data;
    }

    /**
     * Check Line Type on file.
     *
     * @param $value
     * @return bool
     */
    private function isByUserCondition($value)
    {
        if (!isset($value['type'])) {
            return false;
        }

        if ($this->fileType == 'Afdt' && $value['type'] == 2) {
            return true;
        }

        if ($this->fileType == 'Afd' && $value['type'] == 3) {
            return true;
        }

        if ($this->fileType == 'Acjef' && $value['type'] == 3) {
            return true;
        }

        return false;
    }

    /**
     * Get By User on AFDT files.
     *
     * @return array
     */
    private function getByUserAfdt()
    {
        foreach ($this->fileArray as $value) {
            if ($this->isByUserCondition($value)) {
                $this->userArray[$value['identityNumber']][$value['clockDate']->format('dmY')][$value['directionOrder']][] = [
                    'sequency'  => $value['sequency'],
                    'dateTime'  => $value['clockDate']->setTime($value['clockTime']['hour'], $value['clockTime']['minute']),
                    'reason'    => $value['reason'],
                    'direction' => $value['direction'],
                    'type'      => $value['registryType'],
                ];
            }
        }

        return $this->userArray;
    }

    /**
     * Get By User on ACJEF files.
     *
     * @return array
     */
    private function getByUserAcjef()
    {
        foreach ($this->fileArray as $value) {
            if ($this->isByUserCondition($value)) {
                $this->userArray[$value['identityNumber']][] = [
                    'sequency'              => $value['sequency'],
                    'type'                  => $value['type'],
                    'startDate'             => $value['startDate']->format('dmY'),
                    'firstHour'             => $value['firstHour'],
                    'hourCode'              => $value['hourCode'],
                    'hourCode'              => $value['hourCode'],
                    'dayTime'               => $value['dayTime'],
                    'nightTime'             => $value['nightTime'],
                    'overtime1'             => $value['overtime1'],
                    'overtime1'             => $value['overtime1'],
                    'overtimePercentage1'   => $value['overtimePercentage1'],
                    'overtimeModality1'     => $value['overtimeModality1'],
                    'overtime2'             => $value['overtime2'],
                    'overtimePercentage2'   => $value['overtimePercentage2'],
                    'overtimeModality2'     => $value['overtimeModality2'],
                    'overtime3'             => $value['overtime3'],
                    'overtimePercentage3'   => $value['overtimePercentage3'],
                    'overtimeModality3'     => $value['overtimeModality3'],
                    'overtime4'             => $value['overtime4'],
                    'overtimePercentage4'   => $value['overtimePercentage4'],
                    'overtimeModality4'     => $value['overtimeModality4'],
                    'hourAbsencesLate'      => $value['hourAbsencesLate'],
                    'hourSinalCompensate'   => $value['hourSinalCompensate'],
                    'hourBalanceCompensate' => $value['hourBalanceCompensate'],
                ];
            }
        }

        return $this->userArray;
    }


    /**
     * Get All on ACJEF files.
     *
     * @return array
     */
    private function getAllAcjef()
    {

        $data = [];

        foreach ($this->fileArray as $value) {
            if (!$this->isByUserCondition($value) && array_key_exists('type', $value)) {

                if ($value['type'] == 1) {
                    $data['header'] = $this->header($value);
                }

                if ($value['type'] == 2) {
                    $data['contractualHours'][] = [
                        'sequency'   => $value['sequency'],
                        'type'       => $value['type'],
                        'hourCode'   => $value['hourCode'],
                        'startTime'  => $value['startTime'],
                        'startBreak' => $value['startBreak'],
                        'endBreak'   => $value['endBreak'],
                        'endTime'    => $value['endTime'],
                    ];
                }
            }
        }

        $data['detail'] = $this->getByUserAcjef();

        return $data;
    }

    private function header($value)
    {
        return [
            'sequency'       => $value['sequency'],
            'type'           => $value['type'],
            'entityType'     => $value['entityType'],
            'entityNumber'   => $value['entityNumber'],
            'cei'            => $value['cei'],
            'name'           => $value['name'],
            'startDate'      => $value['startDate']->format('dmY'),
            'endDate'        => $value['endDate']->format('dmY'),
            'generationDate' => $value['generationDate'],
            'generationTime' => $value['generationTime'],
        ];
    }
}
