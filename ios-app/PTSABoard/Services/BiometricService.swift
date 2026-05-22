import Foundation
import LocalAuthentication

enum BiometricService {

    enum BiometricKind {
        case faceID, touchID, opticID, none
    }

    static var available: BiometricKind {
        let ctx = LAContext()
        var err: NSError?
        guard ctx.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &err) else {
            return .none
        }
        switch ctx.biometryType {
        case .faceID:  return .faceID
        case .touchID: return .touchID
        case .opticID: return .opticID
        default:       return .none
        }
    }

    /// Prompt FaceID / TouchID. Falls back to device passcode if biometrics
    /// fail/aren't enrolled.
    @MainActor
    static func authenticate(reason: String) async throws {
        let ctx = LAContext()
        ctx.localizedReason = reason
        ctx.localizedFallbackTitle = "Use Passcode"

        var err: NSError?
        guard ctx.canEvaluatePolicy(.deviceOwnerAuthentication, error: &err) else {
            throw err ?? NSError(domain: "Biometric", code: -1)
        }

        try await withCheckedThrowingContinuation { (cont: CheckedContinuation<Void, Error>) in
            ctx.evaluatePolicy(
                .deviceOwnerAuthentication,
                localizedReason: reason
            ) { success, evalErr in
                if success {
                    cont.resume()
                } else {
                    cont.resume(throwing: evalErr ?? NSError(domain: "Biometric", code: -2))
                }
            }
        }
    }
}
