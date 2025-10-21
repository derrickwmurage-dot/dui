<?php

namespace App\Livewire\More;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;

class Careers extends Component
{
    use Toast;

    public $jobs = [];
    public $requirements = [];
    public $responsibilities = [];
    public bool $createJobModal = false;
    public bool $jobDetailsModal = false;
    public bool $editJobModal = false;
    public bool $deleteJobModal = false;
    public $selectedJob;
    public $companyName;
    public $jobTitle;
    public $salaryRange;
    public $location;
    public $jobLevel;
    public $website;
    public $aboutUs;
    public $roleOverview;
    public $isFullTime;
    public $jobType;
    public $jobId;
    public $userId;

    public function mount()
    {
        $this->userId = session('firebase_user');
        $this->fetchJobs();
    }

    public function openjobDetailsModal($index)
    {
        $this->selectedJob = $this->jobs[$index];
        $this->jobDetailsModal = true;
    }

    public function openEditJobModal($index)
    {
        $this->selectedJob = $this->jobs[$index];
        if ($this->selectedJob['userId'] === $this->userId) {
            $this->loadJobData();
            $this->editJobModal = true;
        } else {
            $this->error(
                title: 'You are not authorized to edit this job.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function openDeleteJobModal($index)
    {
        $this->selectedJob = $this->jobs[$index];
        if ($this->selectedJob['userId'] === $this->userId) {
            $this->deleteJobModal = true;
        } else {
            $this->error(
                title: 'You are not authorized to delete this job.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function closeCreateJobModal()
    {
        $this->createJobModal = false;
    }

    public function closeEditJobModal()
    {
        $this->editJobModal = false;
    }

    public function closeDeleteJobModal()
    {
        $this->deleteJobModal = false;
    }

    public function loadJobData()
    {
        $this->jobId = $this->selectedJob['id'];
        $this->companyName = $this->selectedJob['company'];
        $this->jobTitle = $this->selectedJob['title'];
        $this->salaryRange = $this->selectedJob['salary'];
        $this->location = $this->selectedJob['location'];
        $this->jobLevel = $this->selectedJob['level'];
        $this->website = $this->selectedJob['website'];
        $this->aboutUs = $this->selectedJob['aboutUs'];
        $this->roleOverview = $this->selectedJob['roleOverview'];
        $this->responsibilities = $this->selectedJob['responsibilities'];
        $this->requirements = $this->selectedJob['requirements'];
        $this->isFullTime = $this->selectedJob['fullTime'];
        $this->jobType = $this->selectedJob['jobType'];
    }

    public function fetchJobs()
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $collection = $firestore->database()->collection('Careers');

            $documents = $collection->documents();
            $this->jobs = [];

            foreach ($documents as $document) {
                $data = $document->data();
                $data['id'] = $document->id();

                // Convert Firestore timestamp to string
                if (isset($data['datePosted']) && $data['datePosted'] instanceof \Google\Cloud\Core\Timestamp) {
                    $data['datePosted'] = $data['datePosted']->get()->format('Y-m-d H:i:s');
                }

                $this->jobs[] = $data;
            }

            Log::info('Jobs Data:', $this->jobs);

        } catch (\Exception $e) {
            report($e);
        }
    }

    public function createJob()
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $collection = $firestore->database()->collection('Careers');

            $jobData = [
                'company' => $this->companyName,
                'title' => $this->jobTitle,
                'salary' => $this->salaryRange,
                'location' => $this->location,
                'level' => $this->jobLevel,
                'website' => $this->website,
                'aboutUs' => $this->aboutUs,
                'roleOverview' => $this->roleOverview,
                'responsibilities' => $this->responsibilities,
                'requirements' => $this->requirements,
                'fullTime' => $this->isFullTime,
                'jobType' => $this->jobType,
                'datePosted' => now(),
                'userId' => $this->userId,
            ];

            $collection->add($jobData);

            $this->success(
                title: 'Job created successfully',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            $this->closeCreateJobModal();
            $this->fetchJobs();

        } catch (\Exception $e) {
            report($e);
            $this->error(
                title: 'Failed to create job.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function updateJob()
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $collection = $firestore->database()->collection('Careers');

            $jobData = [
                'company' => $this->companyName,
                'title' => $this->jobTitle,
                'salary' => $this->salaryRange,
                'location' => $this->location,
                'level' => $this->jobLevel,
                'website' => $this->website,
                'aboutUs' => $this->aboutUs,
                'roleOverview' => $this->roleOverview,
                'responsibilities' => $this->responsibilities,
                'requirements' => $this->requirements,
                'fullTime' => $this->isFullTime,
                'jobType' => $this->jobType,
                'datePosted' => now(),
                'userId' => $this->userId,
            ];

            $collection->document($this->jobId)->set($jobData);

            $this->success(
                title: 'Job updated successfully',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            $this->closeEditJobModal();
            $this->fetchJobs();

        } catch (\Exception $e) {
            report($e);
            $this->error(
                title: 'Failed to update job.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function deleteJob()
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $collection = $firestore->database()->collection('Careers');

            $collection->document($this->selectedJob['id'])->delete();

            $this->success(
                title: 'Job deleted successfully',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            $this->closeDeleteJobModal();
            $this->fetchJobs();

        } catch (\Exception $e) {
            report($e);
            $this->error(
                title: 'Failed to delete job.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function openCreateJobModal()
    {
        $this->createJobModal = true;
    }

    public function render()
    {
        return view('livewire.more.careers');
    }
}