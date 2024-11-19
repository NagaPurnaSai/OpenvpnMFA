#!/bin/sh
#
# Sample script to verify MFA using oath-tool

passfile=$1

# Get the user/pass from the tmp file
user=$(head -1 "$passfile")
pass=$(tail -1 "$passfile")

USERNAME=$user

# Get the expiration details for the user
EXPIRY_INFO=$(chage -l "$USERNAME")

# Extract relevant details
PASSWORD_EXPIRES=$(echo "$EXPIRY_INFO" | grep "Password expires" | awk -F ': ' '{print $2}')
ACCOUNT_EXPIRES=$(echo "$EXPIRY_INFO" | grep "Account expires" | awk -F ': ' '{print $2}')

echo $PASSWORD_EXPIRES
echo $ACCOUNT_EXPIRES

# Function to check if a given date is in the past
is_date_expired() {
    local date="$1"
    [[ "$(date -d "$date" +%s)" -lt "$(date +%s)" ]]
}

# Check password expiration
if [[ "$PASSWORD_EXPIRES" != "never" ]]; then
    if is_date_expired "$PASSWORD_EXPIRES"; then
        exit 1
    fi
fi

# Check account expiration
if [[ "$ACCOUNT_EXPIRES" != "never" ]]; then
    if is_date_expired "$ACCOUNT_EXPIRES"; then
        exit 1
    fi
fi

# Fetch the secret from the user's file
secret_file="/etc/openvpn/users/$user"
if [ -f "$secret_file" ]; then
    # Extract the first line (raw TOTP secret)
    secret=$(head -n 1 "$secret_file" | grep -v '^#' | tr -d '[:space:]')
else
    echo "Error: Secret file for user '$user' not found."
    exit 1
fi

# Calculate the code we should expect
code=$(oathtool --totp -b "$secret")

if [ "$code" = "$pass" ]; then
    exit 0
fi

# See if we have password and MFA, or just MFA
if echo "$pass" | grep -q -i ':'; then
    # Extract the password and MFA token
    realpass=$(echo "$pass" | cut -d: -f2)
    mfatoken=$(echo "$pass" | cut -d: -f3)

    realpass=$(echo $realpass | base64 --decode)
    mfatoken=$(echo $mfatoken | base64 --decode)

    # Verify the password (you can implement a real password verification method here)
    result=$(expect -c "
        spawn /usr/local/bin/pamtester login \"$user\" authenticate
        expect \"Password:\"
        send \"$realpass\r\"
        expect eof
    ")

    if echo "$result" | grep -q "successfully"; then
        status="success"
    else
        status="failure"
    fi

    if [ "$status" = "success" ]; then
        # Password is correct, now check the MFA token
        if [ "$mfatoken" = "$code" ]; then
            exit 0  # Successful password and MFA verification
        fi
    fi
fi

# If we make it here, auth hasn't succeeded, don't grant access
exit 1
