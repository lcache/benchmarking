# benchmarking
Performance and Scalability Tests

## Setup

    sudo dnf install -y parallel

## Usage

1. Google Cloud SQL certificate files should be in the project root directory.

2. Run the tests:

    php DatabaseL2.php init $HOST
    seq 10 | parallel -n0 php DatabaseL2.php run $HOST

