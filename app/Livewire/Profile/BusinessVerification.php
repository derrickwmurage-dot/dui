<?php

namespace App\Livewire\Profile;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Mary\Traits\Toast;
use App\Mail\ProfileUpdatedMail;
use Illuminate\Support\Facades\Mail;

class BusinessVerification extends Component
{
    use Toast;
    public $step = 1;
    public $formData = [];

    public function mount()
    {
        $this->loadExistingData();
    }

    public function loadExistingData()
    {
        $userId = session('firebase_user');
        
        if (empty($userId)) {
            $this->error('User ID is not set. Please log in again.');
            return;
        }
    
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $profileRef = $firestore->database()
            ->collection('Profile')
            ->document($userId);
    
        $document = $profileRef->snapshot();
    
        if ($document->exists()) {
            $data = $document->data();
            
            // Map stored Firestore field names to form field names
            // General Info
            if (isset($data['GeneralInfo'])) {
                $this->formData['companyName'] = $data['GeneralInfo']['generalCompany'] ?? '';
                $this->formData['ownerFullNames'] = $data['GeneralInfo']['generalNames'] ?? '';
                $this->formData['ownerPassportId'] = $data['GeneralInfo']['generalId'] ?? '';
                $this->formData['dateOfIncorporation'] = $data['GeneralInfo']['generalIncorporation'] ?? '';
                $this->formData['ownerDetails'] = $data['GeneralInfo']['generalOwner'] ?? '';
                $this->formData['operatingCountry'] = $data['GeneralInfo']['generalCountry'] ?? '';
                $this->formData['shareholderResolution'] = $data['GeneralInfo']['resolution'] ?? false;
                $this->formData['currentAgreements'] = $data['GeneralInfo']['shareAgreement'] ?? false;
                $this->formData['capitalChanges'] = $data['GeneralInfo']['capitalChanges'] ?? false;
                $this->formData['thirdPartySecurity'] = $data['GeneralInfo']['securityIssued'] ?? false;
            }
    
            // Business Info
            if (isset($data['BusinessInfo'])) {
                $this->formData['mainBusinessActivity'] = $data['BusinessInfo']['businessDescription'] ?? '';
                $this->formData['otherBusinessDetails'] = $data['BusinessInfo']['businessOther'] ?? '';
                $this->formData['sharesInOtherCompanies'] = $data['BusinessInfo']['shares'] ?? false;
                $this->formData['tradeAssociation'] = $data['BusinessInfo']['tradeAssociation'] ?? false;
                $this->formData['competitorsList'] = $data['BusinessInfo']['businessCompetition'] ?? '';
                $this->formData['restrictiveAgreements'] = $data['BusinessInfo']['contractRestrict'] ?? false;
                $this->formData['fairTradingInvestigations'] = $data['BusinessInfo']['investigations'] ?? false;
                $this->formData['customerTerritoryRestrictions'] = $data['BusinessInfo']['jurisdiction'] ?? false;
                $this->formData['longTermContracts'] = $data['BusinessInfo']['contract'] ?? false;
                $this->formData['creditAgreements'] = $data['BusinessInfo']['businessCredit'] ?? '';
                $this->formData['terminationAgreements'] = $data['BusinessInfo']['businessAgreements'] ?? '';
                $this->formData['licensesDistributionAgreements'] = $data['BusinessInfo']['licences'] ?? false;
                $this->formData['controlChangeArrangements'] = $data['BusinessInfo']['arrangementsControl'] ?? false;
                $this->formData['monetaryImpactArrangements'] = $data['BusinessInfo']['arrangementsMonetary'] ?? false;
                $this->formData['materialContractsUnderNegotiation'] = $data['BusinessInfo']['contractNegatiations'] ?? false;
                $this->formData['restrictiveContracts'] = $data['BusinessInfo']['agreements'] ?? false;
                $this->formData['customersMoreThan5Percent'] = $data['BusinessInfo']['customersRevenue'] ?? false;
                $this->formData['suppliersMoreThan5Percent'] = $data['BusinessInfo']['suppliers'] ?? false;
                $this->formData['materialContracts'] = $data['BusinessInfo']['materialContracts'] ?? false;
            }
    
            // Accounting Info
            if (isset($data['AccountingInfo'])) {
                $this->formData['accountingStandard'] = $data['AccountingInfo']['accountingStandard'] ?? false;
                $this->formData['accountingStandardForm'] = $data['AccountingInfo']['accountingStandardForm'] ?? '';
                $this->formData['bankersDetails'] = $data['AccountingInfo']['accountingCompany'] ?? '';
                $this->formData['accountDetails'] = $data['AccountingInfo']['accountingNames'] ?? '';
                $this->formData['debtSecurities'] = $data['AccountingInfo']['debtSecurities'] ?? false;
                $this->formData['loansGiven'] = $data['AccountingInfo']['loans'] ?? false;
                $this->formData['financialForecasts'] = $data['AccountingInfo']['financial'] ?? false;
                $this->formData['grantsReceived'] = $data['AccountingInfo']['grants'] ?? false;
                $this->formData['creditSalesAgreements'] = $data['AccountingInfo']['creditSales'] ?? false;
                $this->formData['guaranteesGiven'] = $data['AccountingInfo']['guarantees'] ?? false;
                $this->formData['liabilitiesOutstanding'] = $data['AccountingInfo']['liabilities'] ?? false;
                $this->formData['reorganization'] = $data['AccountingInfo']['reorganizationNext'] ?? false;
                $this->formData['previousReorganizations'] = $data['AccountingInfo']['reorganizationPast'] ?? false;
                $this->formData['acquireDivestContracts'] = $data['AccountingInfo']['contractShares'] ?? false;
            }
    
            // Asset Info
            if (isset($data['AssetInfo'])) {
                $this->formData['companyAssets'] = $data['AssetInfo']['assetCompany'] ?? '';
                $this->formData['companyOwnsProperty'] = $data['AssetInfo']['property'] ?? false;
            }
    
            // Intellectual Property
            if (isset($data['IntellectualProperty'])) {
                $this->formData['companyOwnsIntellectualProperty'] = $data['IntellectualProperty']['patents'] ?? false;
                $this->formData['intellectualPropertyRights'] = $data['IntellectualProperty']['intellectualPeople'] ?? '';
                $this->formData['iprExploitation'] = $data['IntellectualProperty']['intellectualDecisions'] ?? '';
                $this->formData['iprDisputes'] = $data['IntellectualProperty']['dispute'] ?? false;
            }
    
            // Employment Policy
            if (isset($data['EmploymentPolicy'])) {
                $this->formData['disciplinaryActions'] = $data['EmploymentPolicy']['disciplinaryAction'] ?? false;
                $this->formData['tradeUnionMembers'] = $data['EmploymentPolicy']['tradeUnion'] ?? false;
                $this->formData['financialSupportLiability'] = $data['EmploymentPolicy']['liability'] ?? false;
                $this->formData['directorsBackground'] = $data['EmploymentPolicy']['directorsBankruptcy'] ?? false;
                $this->formData['directorsMaterialInterest'] = $data['EmploymentPolicy']['materialInterest'] ?? false;
                $this->formData['directorsCompetingBusiness'] = $data['EmploymentPolicy']['interestCompetition'] ?? false;
            }
    
            // Compliance ESG
            if (isset($data['ComplianceESG'])) {
                $this->formData['complianceDescription'] = $data['ComplianceESG']['complianceCircumstances'] ?? '';
                $this->formData['statutoryDuty'] = $data['ComplianceESG']['complianceOfficer'] ?? '';
                $this->formData['disputesWithEmployees'] = $data['ComplianceESG']['disputes'] ?? false;
                $this->formData['licenseSuspension'] = $data['ComplianceESG']['complianceSituations'] ?? '';
                $this->formData['insuranceCover'] = $data['ComplianceESG']['complianceWithdrawals'] ?? '';
                $this->formData['insuranceClaim'] = $data['ComplianceESG']['complianceInsurance'] ?? '';
                $this->formData['dataProcessing'] = $data['ComplianceESG']['personalData'] ?? false;
                $this->formData['minorData'] = $data['ComplianceESG']['dataMinors'] ?? false;
                $this->formData['dataProtectionCompliant'] = $data['ComplianceESG']['dataProtectionAct'] ?? false;
                $this->formData['thirdPartyDataProcessing'] = $data['ComplianceESG']['thirdPartyProcess'] ?? false;
                $this->formData['softwareCopyright'] = $data['ComplianceESG']['copyrightSoftware'] ?? false;
                $this->formData['softwareAccess'] = $data['ComplianceESG']['complianceEvents'] ?? '';
                $this->formData['itDispute'] = $data['ComplianceESG']['complianceDisputes'] ?? '';
                $this->formData['domainNames'] = $data['ComplianceESG']['complianceDomain'] ?? '';
                $this->formData['healthSafetyRiskAssessment'] = $data['ComplianceESG']['complianceHealth'] ?? '';
                $this->formData['healthSafetyCommunication'] = $data['ComplianceESG']['communicationSafety'] ?? false;
                $this->formData['environmentalNonCompliance'] = $data['ComplianceESG']['noticeEnvironment'] ?? false;
            }
    
            // Convert string booleans to actual booleans
            $this->convertStringBooleansToActualBooleans();
        }
    }
    
    private function convertStringBooleansToActualBooleans()
    {
        $booleanFields = [
            'shareholderResolution', 'currentAgreements', 'capitalChanges', 'thirdPartySecurity',
            'sharesInOtherCompanies', 'tradeAssociation', 'restrictiveAgreements', 'fairTradingInvestigations',
            'customerTerritoryRestrictions', 'longTermContracts', 'licensesDistributionAgreements',
            'controlChangeArrangements', 'monetaryImpactArrangements', 'materialContractsUnderNegotiation',
            'restrictiveContracts', 'customersMoreThan5Percent', 'suppliersMoreThan5Percent', 'materialContracts',
            'debtSecurities', 'loansGiven', 'financialForecasts', 'grantsReceived', 'creditSalesAgreements',
            'guaranteesGiven', 'liabilitiesOutstanding', 'reorganization', 'previousReorganizations',
            'acquireDivestContracts', 'companyOwnsProperty', 'companyOwnsIntellectualProperty', 'iprDisputes',
            'disciplinaryActions', 'tradeUnionMembers', 'financialSupportLiability', 'directorsBackground',
            'directorsMaterialInterest', 'directorsCompetingBusiness', 'disputesWithEmployees', 'dataProcessing',
            'minorData', 'dataProtectionCompliant', 'thirdPartyDataProcessing', 'softwareCopyright',
            'healthSafetyCommunication', 'environmentalNonCompliance', 'accountingStandard'
        ];
    
        foreach ($booleanFields as $field) {
            if (isset($this->formData[$field])) {
                $this->formData[$field] = filter_var($this->formData[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
    }

    public function nextStep()
    {
        $this->step++;
    }

    public function previousStep()
    {
        $this->step--;
    }

    public function submit()
    {
        $userId = session('firebase_user');
        
        if (empty($userId)) {
            $this->error('User ID is not set. Please log in again.');
            return;
        }
    
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $profileRef = $firestore->database()
            ->collection('Profile')
            ->document($userId);
    
        // Convert string booleans to actual booleans before saving
        $this->convertStringBooleansToActualBooleans();
    
        $profileData = [
            'userId' => $userId,
            'GeneralInfo' => [
                'generalCompany' => $this->formData['companyName'] ?? '',
                'generalNames' => $this->formData['ownerFullNames'] ?? '',
                'generalId' => $this->formData['ownerPassportId'] ?? '',
                'generalIncorporation' => $this->formData['dateOfIncorporation'] ?? '',
                'generalOwner' => $this->formData['ownerDetails'] ?? '',
                'generalCountry' => $this->formData['operatingCountry'] ?? '',
                'resolution' => $this->formData['shareholderResolution'] ?? false,
                'shareAgreement' => $this->formData['currentAgreements'] ?? false,
                'capitalChanges' => $this->formData['capitalChanges'] ?? false,
                'securityIssued' => $this->formData['thirdPartySecurity'] ?? false,
            ],
            'BusinessInfo' => [
                'businessDescription' => $this->formData['mainBusinessActivity'] ?? '',
                'businessOther' => $this->formData['otherBusinessDetails'] ?? '',
                'shares' => $this->formData['sharesInOtherCompanies'] ?? false,
                'tradeAssociation' => $this->formData['tradeAssociation'] ?? false,
                'businessCompetition' => $this->formData['competitorsList'] ?? '',
                'contractRestrict' => $this->formData['restrictiveAgreements'] ?? false,
                'investigations' => $this->formData['fairTradingInvestigations'] ?? false,
                'jurisdiction' => $this->formData['customerTerritoryRestrictions'] ?? false,
                'contract' => $this->formData['longTermContracts'] ?? false,
                'businessCredit' => $this->formData['creditAgreements'] ?? '',
                'businessAgreements' => $this->formData['terminationAgreements'] ?? '',
                'licences' => $this->formData['licensesDistributionAgreements'] ?? false,
                'arrangementsControl' => $this->formData['controlChangeArrangements'] ?? false,
                'arrangementsMonetary' => $this->formData['monetaryImpactArrangements'] ?? false,
                'contractNegatiations' => $this->formData['materialContractsUnderNegotiation'] ?? false,
                'agreements' => $this->formData['restrictiveContracts'] ?? false,
                'customersRevenue' => $this->formData['customersMoreThan5Percent'] ?? false,
                'suppliers' => $this->formData['suppliersMoreThan5Percent'] ?? false,
                'materialContracts' => $this->formData['materialContracts'] ?? false,
            ],
            'AccountingInfo' => [
                'accountingStandard' => $this->formData['accountingStandard'] ?? false,
                'accountingStandardForm' => $this->formData['accountingStandardForm'] ?? '',
                'accountingCompany' => $this->formData['bankersDetails'] ?? '',
                'accountingNames' => $this->formData['accountDetails'] ?? '',
                'debtSecurities' => $this->formData['debtSecurities'] ?? false,
                'loans' => $this->formData['loansGiven'] ?? false,
                'financial' => $this->formData['financialForecasts'] ?? false,
                'grants' => $this->formData['grantsReceived'] ?? false,
                'creditSales' => $this->formData['creditSalesAgreements'] ?? false,
                'guarantees' => $this->formData['guaranteesGiven'] ?? false,
                'liabilities' => $this->formData['liabilitiesOutstanding'] ?? false,
                'reorganizationNext' => $this->formData['reorganization'] ?? false,
                'reorganizationPast' => $this->formData['previousReorganizations'] ?? false,
                'contractShares' => $this->formData['acquireDivestContracts'] ?? false,
            ],
            'AssetInfo' => [
                'assetCompany' => $this->formData['companyAssets'] ?? '',
                'property' => $this->formData['companyOwnsProperty'] ?? false,
            ],
            'IntellectualProperty' => [
                'patents' => $this->formData['companyOwnsIntellectualProperty'] ?? false,
                'intellectualPeople' => $this->formData['intellectualPropertyRights'] ?? '',
                'intellectualDecisions' => $this->formData['iprExploitation'] ?? '',
                'dispute' => $this->formData['iprDisputes'] ?? false,
            ],
            'EmploymentPolicy' => [
                'disciplinaryAction' => $this->formData['disciplinaryActions'] ?? false,
                'tradeUnion' => $this->formData['tradeUnionMembers'] ?? false,
                'liability' => $this->formData['financialSupportLiability'] ?? false,
                'directorsBankruptcy' => $this->formData['directorsBackground'] ?? false,
                'materialInterest' => $this->formData['directorsMaterialInterest'] ?? false,
                'interestCompetition' => $this->formData['directorsCompetingBusiness'] ?? false,
            ],
            'ComplianceESG' => [
                'complianceCircumstances' => $this->formData['complianceDescription'] ?? '',
                'complianceOfficer' => $this->formData['statutoryDuty'] ?? '',
                'disputes' => $this->formData['disputesWithEmployees'] ?? false,
                'complianceSituations' => $this->formData['licenseSuspension'] ?? '',
                'complianceWithdrawals' => $this->formData['insuranceCover'] ?? '',
                'complianceInsurance' => $this->formData['insuranceClaim'] ?? '',
                'personalData' => $this->formData['dataProcessing'] ?? false,
                'dataMinors' => $this->formData['minorData'] ?? false,
                'dataProtectionAct' => $this->formData['dataProtectionCompliant'] ?? false,
                'thirdPartyProcess' => $this->formData['thirdPartyDataProcessing'] ?? false,
                'copyrightSoftware' => $this->formData['softwareCopyright'] ?? false,
                'complianceEvents' => $this->formData['softwareAccess'] ?? '',
                'complianceDisputes' => $this->formData['itDispute'] ?? '',
                'complianceDomain' => $this->formData['domainNames'] ?? '',
                'complianceHealth' => $this->formData['healthSafetyRiskAssessment'] ?? '',
                'communicationSafety' => $this->formData['healthSafetyCommunication'] ?? false,
                'noticeEnvironment' => $this->formData['environmentalNonCompliance'] ?? false,
            ],
        ];
    
        // Calculate completion percentage
        $totalFields = count($this->formData);
        $filledFields = count(array_filter($this->formData, function($value) {
            return $value !== '' && $value !== null;  // This counts false as filled
        }));
        $completionPercentage = ($filledFields / $totalFields) * 100;
    
        // Add completion percentage to profile data
        $profileData['completion'] = [
            'completion' => $completionPercentage
        ];
    
        $profileRef->set($profileData);

        $ccEmails = config('mail.admin.cc');

        Mail::to(config('mail.admin.to'))
        ->cc(config($ccEmails))
        ->send(new ProfileUpdatedMail($userId, $completionPercentage, $profileData));
    
        $this->success('Profile updated successfully');
        return redirect()->route('profile');
    }

    public function render()
    {
        return view('livewire.profile.business-verification');
    }
}