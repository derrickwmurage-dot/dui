# Invest in Africa - Empowering African Ventures

Invest in Africa is a platform that connects startups with potential investors and partners. Our mission is to support innovative African startups and ventures, driving growth and creating lasting impact.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Features

- **User Authentication**: Secure user authentication and authorization.
- **Profile Management**: Comprehensive profile management for users and businesses.
- **Business Verification**: Multi-step business verification process.
- **Marketplace**: Platform for startups to showcase their ventures and attract investors.
- **Investments**: Secure and easy investment process.
- **Notifications**: Real-time notifications for important events.
- **Responsive Design**: Fully responsive design for all devices.

## Installation

### Prerequisites

- PHP 8.2 or higher
- Composer
- Node.js and npm
- Firebase account with Firestore and Storage enabled

### Steps

1. **Clone the repository**:
    ```sh
    git clone https://github.com/yourusername/invest-in-africa.git
    cd invest-in-africa
    ```

2. **Install PHP dependencies**:
    ```sh
    composer install
    ```

3. **Install Node.js dependencies**:
    ```sh
    npm install
    ```

4. **Copy the example environment file and configure it**:
    ```sh
    cp .env.example .env
    ```

    Update the [.env](http://_vscodecontentref_/0) file with your database and Firebase credentials.

5. **Generate application key**:
    ```sh
    php artisan key:generate
    ```

6. **Run database :
migrations**    ```sh
    php artisan migrate
    ```

7. **Build frontend assets**:
    ```sh
    npm run build
    ```

8. **Start the development server**:
    ```sh
    php artisan serve
    ```

## Usage

### Running the Application

To start the application, run the following command:

```sh
php artisan serve