<?php require_once(DIR . '/../ToolHelper.php'); require_once(DIR . '/PhoneNumberFilter.php');

class OtherEmergencyContactsImporter extends Model_Import_Base {

    /**

    @param $input
    @return array
    Function takes name or names and splits them into
    one or two names based on so far identified delimiters
    adds surname of second person to first
    and returns array with both person formed name */ protected function filterNames($input) { $validNames = array();

// Define all delimiters for names $namesSeparator = array( '&' => '', ' AND ' => '', '/' => '', '\' => '', );

// Replace all white spaces $input = trim(preg_replace('/\s+/', ' ', $input)); $input = preg_replace('/\s+/', ' ', strtoupper($input)); $names = array($input); foreach ($namesSeparator as $delimiter => $replace) { if (false !== strpos($input, $delimiter)) { $names = explode($delimiter, $input); break; } }

    $names = array_unique($names); // Trims all white characters within name array_walk($names, function (&$val) { $val = trim($val); });

    $namesCount = count($names); // Assigns Name to name1 if there is no delimiter from the pattern array if ($namesCount == 1) { $validNames[] = $names[0]; } // If there is more than one name, takes second name and assigns value to name2 and adds surname to name1 if ($namesCount == 2) { $name2 = $names[1]; $names2 = explode(" ", $name2); array_shift($names2); $validNames[] = $names[0] . ' ' . implode(' ', $names2); $validNames[] = $name2; }

    return $validNames; }

    public function processFile($fileName, $startRow) {

        $staffXml = simplexml_load_file($fileName);

        $columnNames = [];

        $c = 0;
        foreach ($staffXml->METADATA->FIELD as $fieldXml) {
            $c++;
            $columnNames[$c] = (string) $fieldXml->attributes()->NAME;
        }

        $rows = [];
        foreach ($staffXml->RESULTSET->ROW as $rowXml) {
            $rowData = [];
            $c = 0;
            foreach ($rowXml->COL as $colIndex=>$colData) {
                $c++;
                $rowData[$columnNames[$c]] = (string) $colData->DATA;
            }
            $rows[] = $rowData;
        }

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber >= $startRow) {
                echo "Processing Row: $rowNumber\n";
// if($rowNumber >= 400) { $this->processRow($rowNumber, $row, 4); // } } else echo "Skipping row :" . $rowNumber . " because it is less than startRow: $startRow\n"; } echo "Completed processing of ".($rowNumber+1)." rows\n";

            }

            public function processRow($rowNumber, $row, $emergencyContactPriority) { // $input = 'Margaret Cleator Grandma 817791/479170w/978444/B Harvey 628287/481674'; if($row['Contacts (other)']) { $input = $row['Contacts (other)']; preg_match_all('/(\D{3,})([\d\s\/who]+)/si', $input, $matches);

                $nameString = null;
                $numberString = null;

                $names = null;
                $telephoneNumbers = null;

                foreach ($matches[0] as $index => $match) {
                    if (isset($matches[1][$index]) && isset($matches[2][$index])) {
                        $nameString = $matches[1][$index]; // Names
                        $numberString = $matches[2][$index]; // Telephone numbers
                    }

                    // Names
                    $names = $this->filterNames($nameString);
                    // Numbers
// $telephoneNumbers = $this->filterNumber($numberString); }

                    if($names){

                        foreach($names as $name) {
                            if($name) {
                                // Create person for each name
                                $guardianPerson = new \Misa\Model\Person();

                                $names = explode(" ", $name);
                                $titles = array(
                                    'Ms',
                                    'Mr',
                                    'Miss'
                                );

                                foreach($titles as $key => $value) {
                                    if(strpos(strtolower($names[0]), strtolower($value)) !== false) {
                                        unset($names[0]);
                                        break;
                                    }
                                }

                                $firstName = array_shift($names);
                                $lastName = implode(" ", $names);

                                if(strlen(trim($firstName)) > 0) {
                                    $guardianPerson->setLegalFirstName(trim($firstName));
                                }
                                if(strlen(trim($lastName)) > 0) {
                                    $guardianPerson->setLegalLastName(trim($lastName));
                                }
// $guardianPerson->setLegalFirstName($name);

                                if(strlen(trim($name)) >= 1) {
                                    $guardianPerson->tag("class", __CLASS__);
                                    $guardianPerson->tag("guardian_name", $name);

                                    $this->synchronizeUsingUserTags($guardianPerson);
                                }

                                // Create Guardian for every name
                                $guardian = new \Misa\Model\Guardian();
                                $guardian->setPerson($guardianPerson);

                                if(strlen(trim($name)) >= 1) {
                                    $guardian->tag("class", __CLASS__);
                                    $guardian->tag("guardian", $name);

                                    $this->synchronizeUsingUserTags($guardian);
                                }

                                // Create student guardian relationship
                                $pupilUniqueNumber = str_replace('-', '', $row["Unique number"]);
                                $student = $this->getModelBySimpleQuery(
                                    \Misa\Resource\ResourceType::STUDENT,
                                    array(),
                                    array("unique_number" => $pupilUniqueNumber)
                                );

                                $studentGuardianRelationship = new \Misa\Model\StudentGuardianRelationship();
                                $studentGuardianRelationship->setStudent($student);
                                $studentGuardianRelationship->setGuardian($guardian);
                                $studentGuardianRelationship->setEmergencyContactPriority($emergencyContactPriority);

                                $relationship = $this->getModelBySimpleQuery(
                                    \Misa\Resource\ResourceType::GUARDIAN_RELATIONSHIP_TYPE,
                                    array("code" => "OTHR"),
                                    array()
                                );

                                $studentGuardianRelationship->setGuardianRelationshipType($relationship);

                                if(strlen(trim($name)) >= 1) {
                                    $studentGuardianRelationship->tag("class", __CLASS__);
                                    $studentGuardianRelationship->tag("pupil_unique_number", $pupilUniqueNumber);
                                    $studentGuardianRelationship->tag("guardian_name", $name);

                                    $this->synchronizeUsingUserTags($studentGuardianRelationship);
                                }

                                // Create telephone number for every person
                                /** @var \Misa\Model\TelephoneNumber[] $phoneNumbers */
                                $phoneNumbers = PhoneNumberFilter::createPhoneNumbers($numberString);

                                if ($phoneNumbers && strlen(trim($name)) >= 1) {
                                    foreach ($phoneNumbers as $telephoneNumber) {
                                        $telephoneNumber->setNumberOwner($guardian);

                                        $telephoneNumber->tag("class", __CLASS__);
                                        $telephoneNumber->tag("number_owner", $name);
                                        $telephoneNumber->tag("telephone_number", $telephoneNumber->getNumber());

                                        $this->synchronizeUsingUserTags($telephoneNumber);
                                    }
                                }
                            }
                        }
                    }
                }
            } }

        include(DIR."/import-helper.php");

        $env = [ "targetApiEndpoint"=>$targetApiEndpoint ];

        $fileName = $inputFile;

        $importer = new OtherEmergencyContactsImporter($env); $importer->processFile($fileName, $startRow);